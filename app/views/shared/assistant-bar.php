<?php /** The command bar (chat-actions). Fixed bottom, every screen; above the tab bar on a phone; the reply expands upward. */ ?>
<div id="assistant-bar" class="assistant-bar">
    <div id="assistant-reply" class="assistant-reply" aria-live="polite"></div>
    <form id="assistant-form" class="assistant-form"
          hx-post="/assistant/ask.php"
          hx-target="#assistant-reply"
          hx-swap="innerHTML"
          hx-indicator="#assistant-send-btn"
          hx-vals='js:{screen: (document.getElementById("page-content")||{}).dataset?.screen || "",
                       entity: (document.getElementById("page-content")||{}).dataset?.entity || "",
                       record_id: (document.getElementById("page-content")||{}).dataset?.recordId || ""}'
          hx-on::after-request="if(event.detail.successful){this.reset();document.getElementById('assistant-input').focus();}">
        <div class="input-group">
            <span class="input-group-text bg-transparent border-0"><i class="feather-message-circle text-muted"></i></span>
            <input type="text" id="assistant-input" name="utterance" class="form-control border-0"
                   placeholder="Ask or tell Sales CRM…" autocomplete="off" maxlength="2000" aria-label="Ask Sales CRM" />
            <button type="submit" id="assistant-send-btn" class="btn btn-primary" aria-label="Send">
                <i class="feather-send"></i>
            </button>
        </div>
    </form>
</div>
<script>
    (function () {
        document.addEventListener('keydown', function (e) {
            var input = document.getElementById('assistant-input');
            if (!input) return;
            var tag = (e.target.tagName || '').toLowerCase();
            var typing = tag === 'input' || tag === 'textarea' || e.target.isContentEditable;
            if ((e.key === 'k' && (e.metaKey || e.ctrlKey)) || (e.key === '/' && !typing)) {
                e.preventDefault();
                input.focus();
            }
        });
    })();
</script>
