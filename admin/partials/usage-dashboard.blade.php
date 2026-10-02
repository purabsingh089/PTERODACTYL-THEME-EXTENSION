{{--
  Reference partial — AI usage dashboard mount point (call volume, per-model
  breakdown, cost estimate, errors, rate-limit status).

  NOTE: Blueprint installs ONLY the single file bound to admin.view, so this
  section is inlined in admin/view.blade.php behind "PARTIAL: usage-dashboard".
  Data is fetched client-side from /extensions/primus/admin/usage (admin-only,
  CSRF-protected) to keep the page shell fast. Kept for spec-structure parity.
--}}

<div class="prx-usage" id="prx-usage" data-endpoint="/extensions/primus/admin/usage">
    <div class="prx-usage__loading">Loading usage…</div>
</div>
