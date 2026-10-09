<?php /** My signature: the words under the emails I send from Sales CRM (signature_save). Data: signature */ ?>
<form method="post" action="/settings/signature.php" hx-post="/settings/signature.php" hx-target="#page-content" hx-swap="innerHTML" id="signature">
    <?= csrf_field() ?>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Your signature</h5></div><div class="card-body">
        <label class="form-label fs-12 text-muted" for="signature-field-text">Under the emails you send (up to 1000 characters; blank for none)</label>
        <textarea name="signature" id="signature-field-text" class="form-control mb-2" rows="4" maxlength="1000"><?= e($signature) ?></textarea>
        <button type="submit" class="btn btn-light btn-touch w-100" id="signature-save-btn">Save the signature</button>
    </div></div>
</form>
