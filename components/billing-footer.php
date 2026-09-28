<?php if (!defined('UTSAVDESK_ADMIN_HEADER_INCLUDED')) { http_response_code(404); exit; } ?>
<footer class="dashboard-footer"><span>© <?= date('Y') ?> UtsavDesk</span><span>Platform Administration</span></footer>
</main></div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= billingEscape(APP_BASE_URL) ?>/assets/js/admin.js"></script>
<script src="<?= billingEscape(APP_BASE_URL) ?>/assets/js/billing.js"></script>
</body></html>
