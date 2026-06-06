/**
 * WP Task Processor — Dashboard JS
 *
 * Establishes a WebSocket connection (falls back to Server-Sent Events),
 * renders the task list, and live-updates rows on status changes.
 *
 * @package WPTaskProcessor
 */

( function () {
    'use strict';

    // ── Config ─────────────────────────────────────────────────────────────────
    const { root, nonce, ws_url, sse_url, ns, ajaxurl, ajax_nonce } = window.WTP || {};
    const API = `${ root }${ ns }`;

    // ── State ──────────────────────────────────────────────────────────────────
    let currentPage   = 1;
    let currentStatus = '';
    let ws            = null;
    let sse           = null;
    let taskRows      = {};   // task_id → <tr> element

    // ── Boot ───────────────────────────────────────────────────────────────────
    document.addEventListener( 'DOMContentLoaded', function () {
        loadTasks();
        connectRealtime();
        bindEvents();
    } );

    // ── API helpers ────────────────────────────────────────────────────────────
    async function apiFetch( path, opts = {} ) {
        const res = await fetch( `${ API }${ path }`, {
            headers: {
                'Content-Type':  'application/json',
                'X-WP-Nonce':    nonce,
                ...( opts.headers || {} ),
            },
            ...opts,
        } );
        return res.json();
    }

    // ── Task list ──────────────────────────────────────────────────────────────
    async function loadTasks( page = 1, status = '' ) {
        currentPage   = page;
        currentStatus = status;

        const params  = new URLSearchParams( { page, per_page: 20 } );
        if ( status ) params.set( 'status', status );

        const data = await apiFetch( `/tasks?${ params }` );

        renderTable( data.tasks || [] );
        renderPagination( data.total || 0, page );
        updateStats();
    }

    // ── Table rendering ────────────────────────────────────────────────────────
    function renderTable( tasks ) {
        const tbody = document.getElementById( 'wtp-task-tbody' );
        if ( ! tbody ) return;

        if ( tasks.length === 0 ) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#aaa;">No tasks found.</td></tr>';
            taskRows = {};
            return;
        }

        // Keep a map for live-update patches
        taskRows = {};
        tbody.innerHTML = tasks.map( rowHTML ).join( '' );
        tasks.forEach( t => { taskRows[ t.id ] = document.getElementById( `wtp-row-${ t.id }` ); } );
    }

    function rowHTML( task ) {
        const resultStr = task.result ? JSON.stringify( task.result ).slice( 0, 60 ) + '…' : '—';
        const retryBtn  = task.status === 'failed'
            ? `<button class="button button-small wtp-retry-btn" data-id="${ esc( task.id ) }">Retry</button>`
            : '';

        return `
        <tr id="wtp-row-${ esc( task.id ) }" class="wtp-row wtp-row--${ esc( task.status ) }">
            <td><code class="wtp-id">${ esc( task.id ) }</code></td>
            <td><strong>${ esc( task.type ) }</strong></td>
            <td><span class="wtp-badge wtp-badge--${ esc( task.status ) }">${ esc( task.status.toUpperCase() ) }</span></td>
            <td>${ esc( String( task.attempts ) ) }</td>
            <td>${ esc( task.createdAt ) }</td>
            <td><small>${ esc( resultStr ) }</small></td>
            <td>${ retryBtn }</td>
        </tr>`;
    }

    function patchRow( task ) {
        // Update existing row in-place without full re-render
        let tr = document.getElementById( `wtp-row-${ task.id }` );

        if ( ! tr ) {
            // New task — prepend it
            const tbody = document.getElementById( 'wtp-task-tbody' );
            if ( tbody ) {
                tbody.insertAdjacentHTML( 'afterbegin', rowHTML( task ) );
            }
        } else {
            const newTr = document.createElement( 'tbody' );
            newTr.innerHTML = rowHTML( task );
            tr.replaceWith( newTr.firstElementChild );
        }

        taskRows[ task.id ] = document.getElementById( `wtp-row-${ task.id }` );
    }

    // ── Pagination ─────────────────────────────────────────────────────────────
    function renderPagination( total, page ) {
        const container = document.getElementById( 'wtp-pagination' );
        if ( ! container ) return;

        const pages = Math.ceil( total / 20 );
        if ( pages <= 1 ) { container.innerHTML = ''; return; }

        let html = `<span>Total: ${ total } | Page ${ page } of ${ pages }</span> `;
        if ( page > 1 )     html += `<button class="button wtp-page-btn" data-page="${ page - 1 }">← Prev</button> `;
        if ( page < pages ) html += `<button class="button wtp-page-btn" data-page="${ page + 1 }">Next →</button>`;
        container.innerHTML = html;
    }

    // ── Stats ──────────────────────────────────────────────────────────────────
    async function updateStats() {
        const data = await apiFetch( '/stats' );
        const c    = data.counts || {};
        setText( 'stat-pending',    c.pending    || 0 );
        setText( 'stat-processing', c.processing || 0 );
        setText( 'stat-completed',  c.completed  || 0 );
        setText( 'stat-failed',     c.failed     || 0 );
    }

    // ── Real-time connection ───────────────────────────────────────────────────
    function connectRealtime() {
        // Try WebSocket first
        if ( 'WebSocket' in window && ws_url ) {
            tryWebSocket();
        } else {
            trySSE();
        }
    }

    function tryWebSocket() {
        setWsStatus( 'connecting', 'Connecting WS…' );

        try {
            ws = new WebSocket( ws_url );
        } catch ( e ) {
            trySSE();
            return;
        }

        let wsTimeout = setTimeout( () => {
            if ( ws.readyState !== WebSocket.OPEN ) {
                ws.close();
                trySSE();
            }
        }, 3000 );

        ws.onopen = () => {
            clearTimeout( wsTimeout );
            setWsStatus( 'connected', 'WS Connected' );
            ws.send( JSON.stringify( { action: 'subscribe', task_id: 'all' } ) );
            logEvent( 'system', 'WebSocket connected' );
        };

        ws.onmessage = e => {
            try {
                const msg = JSON.parse( e.data );
                handleRealtimeEvent( msg );
            } catch ( err ) { /* ignore malformed */ }
        };

        ws.onerror = () => {
            clearTimeout( wsTimeout );
            setWsStatus( 'error', 'WS Error — using SSE' );
            trySSE();
        };

        ws.onclose = () => {
            setWsStatus( 'disconnected', 'WS closed — reconnecting…' );
            setTimeout( connectRealtime, 5000 );
        };
    }

    function trySSE() {
        if ( sse ) sse.close();

        const url = `${ sse_url }?_wpnonce=${ encodeURIComponent( nonce ) }`;
        sse = new EventSource( url );

        sse.addEventListener( 'task_updated', e => {
            try {
                const task = JSON.parse( e.data );
                handleRealtimeEvent( { event: 'task_updated', task } );
            } catch ( err ) { /* ignore */ }
        } );

        sse.onopen  = () => setWsStatus( 'connected', 'SSE Connected' );
        sse.onerror = () => setWsStatus( 'error', 'SSE Error' );

        logEvent( 'system', 'Fell back to Server-Sent Events' );
    }

    function handleRealtimeEvent( msg ) {
        if ( msg.event === 'task_updated' && msg.task ) {
            patchRow( msg.task );
            updateStats();
            logEvent( msg.task.status, `Task ${ msg.task.id.slice( 0, 8 ) }… → ${ msg.task.status.toUpperCase() }` );
        }

        if ( msg.event === 'heartbeat' ) {
            logEvent( 'heartbeat', `♡ heartbeat ${ msg.ts || '' }` );
        }

        if ( msg.event === 'connected' ) {
            logEvent( 'system', msg.message || 'Server ready' );
        }
    }

    // ── Create task ────────────────────────────────────────────────────────────
    async function createTask( type, idempKey, forceFail ) {
        const payload = forceFail ? { _force_fail: true } : {};
        const body    = { type, payload };
        if ( idempKey ) body.idempotency_key = idempKey;

        const task = await apiFetch( '/tasks', {
            method:  'POST',
            body:    JSON.stringify( body ),
        } );

        if ( task.id ) {
            logEvent( 'info', `Task created: ${ task.id.slice( 0, 8 ) }…` );
            // Subscribe WebSocket to this specific task
            if ( ws && ws.readyState === WebSocket.OPEN ) {
                ws.send( JSON.stringify( { action: 'subscribe', task_id: task.id } ) );
            }
            loadTasks( currentPage, currentStatus );
        } else {
            logEvent( 'error', `Error: ${ task.error || 'Unknown error' }` );
        }

        return task;
    }

    // ── Event log ──────────────────────────────────────────────────────────────
    function logEvent( type, message ) {
        const log   = document.getElementById( 'wtp-event-log' );
        if ( ! log ) return;

        const ts    = new Date().toLocaleTimeString();
        const entry = document.createElement( 'div' );
        entry.className = `wtp-event-entry wtp-event--${ type }`;
        entry.innerHTML = `<span class="wtp-event-ts">${ ts }</span> <span class="wtp-badge wtp-badge--${ type }">${ type.toUpperCase() }</span> ${ esc( message ) }`;
        log.insertBefore( entry, log.firstChild );

        // Cap at 100 entries
        while ( log.children.length > 100 ) {
            log.removeChild( log.lastChild );
        }
    }

    // ── Event bindings ─────────────────────────────────────────────────────────
    function bindEvents() {
        // Create form
        const form = document.getElementById( 'wtp-create-form' );
        if ( form ) {
            form.addEventListener( 'submit', async e => {
                e.preventDefault();
                const type      = document.getElementById( 'wtp-task-type' ).value;
                const idempKey  = document.getElementById( 'wtp-idem-key' ).value.trim();
                const forceFail = document.getElementById( 'wtp-force-fail' ).checked;
                const msg       = document.getElementById( 'wtp-create-msg' );

                msg.textContent = 'Creating…';
                const task = await createTask( type, idempKey, forceFail );
                msg.textContent = task.id
                    ? `Created: ${ task.id.slice( 0, 8 ) }… (status: ${ task.status })`
                    : `Error: ${ task.error }`;
            } );
        }

        // Filter buttons
        document.querySelectorAll( '.wtp-filter-btn' ).forEach( btn => {
            btn.addEventListener( 'click', function () {
                document.querySelectorAll( '.wtp-filter-btn' ).forEach( b => b.classList.remove( 'active' ) );
                this.classList.add( 'active' );
                loadTasks( 1, this.dataset.status );
            } );
        } );

        // Refresh button
        const refreshBtn = document.getElementById( 'wtp-refresh' );
        if ( refreshBtn ) refreshBtn.addEventListener( 'click', () => loadTasks( currentPage, currentStatus ) );

        // Pagination (delegated)
        const pager = document.getElementById( 'wtp-pagination' );
        if ( pager ) {
            pager.addEventListener( 'click', e => {
                const btn = e.target.closest( '.wtp-page-btn' );
                if ( btn ) loadTasks( parseInt( btn.dataset.page, 10 ), currentStatus );
            } );
        }

        // Retry buttons (delegated)
        const tbody = document.getElementById( 'wtp-task-tbody' );
        if ( tbody ) {
            tbody.addEventListener( 'click', async e => {
                const btn = e.target.closest( '.wtp-retry-btn' );
                if ( ! btn ) return;
                const taskId = btn.dataset.id;
                btn.disabled = true;
                btn.textContent = 'Retrying…';
                await apiFetch( `/tasks/${ taskId }/retry`, { method: 'POST' } );
                loadTasks( currentPage, currentStatus );
            } );
        }

        // Clear events
        const clearBtn = document.getElementById( 'wtp-clear-events' );
        if ( clearBtn ) {
            clearBtn.addEventListener( 'click', () => {
                const log = document.getElementById( 'wtp-event-log' );
                if ( log ) log.innerHTML = '';
            } );
        }

        // Generic copy buttons — any .wtp-copy[data-target] copies text of #data-target element
        document.querySelectorAll( '.wtp-copy[data-target]' ).forEach( btn => {
            btn.addEventListener( 'click', function () {
                const target = document.getElementById( this.dataset.target );
                if ( ! target ) return;
                const text = target.value !== undefined ? target.value : target.textContent;
                copyText( text, this );
            } );
        } );
    }

    function copyText( text, btn ) {
        const original = btn.textContent;
        navigator.clipboard.writeText( text ).then( () => {
            btn.textContent = 'Copied!';
            setTimeout( () => { btn.textContent = original; }, 2000 );
        } ).catch( () => {
            const ta = document.createElement( 'textarea' );
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity  = '0';
            document.body.appendChild( ta );
            ta.select();
            document.execCommand( 'copy' );
            document.body.removeChild( ta );
            btn.textContent = 'Copied!';
            setTimeout( () => { btn.textContent = original; }, 2000 );
        } );
    }

    // ── Helpers ────────────────────────────────────────────────────────────────
    function setText( id, val ) {
        const el = document.getElementById( id );
        if ( el ) el.textContent = val;
    }

    function setWsStatus( type, label ) {
        const el = document.getElementById( 'wtp-ws-status' );
        if ( ! el ) return;
        el.textContent = label;
        el.className   = `wtp-badge wtp-badge--${ type }`;
    }

    function esc( str ) {
        const d = document.createElement( 'div' );
        d.textContent = String( str );
        return d.innerHTML;
    }

} )();
