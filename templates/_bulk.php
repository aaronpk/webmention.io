<?php
/**
 * The form the checkboxes on pending rows belong to (by its id, so the rows'
 * own forms are not nested inside it). Included with `require`.
 *
 * @var string $back
 * @var string $csrf
 */
?>
<form id="bulk" action="/approve" method="post" class="bulk-actions">
    <input type="hidden" name="back" value="<?= $back ?>">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <label class="checkbox"><input type="checkbox" data-select-all="bulk"> <span>Select all on this page</span></label>
    <span class="muted small" data-selected-count hidden></span>
    <span class="grow"></span>
    <button type="submit" class="small" formaction="/approve" data-needs-selection>Approve selected</button>
    <button type="submit" class="secondary small" formaction="/reject" data-needs-selection data-confirm="Delete the selected webmentions and block their source URLs?">Reject selected</button>
</form>
