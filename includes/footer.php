<?php
if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); }
if (is_logged_in()): ?>
  </main>
</div>
<?php else: ?>
</main>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/validation.js?v=<?= @filemtime(__DIR__ . '/../assets/js/validation.js') ?: '1' ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/main.js?v=<?= @filemtime(__DIR__ . '/../assets/js/main.js') ?: '1' ?>"></script>
<?php if (!empty($extraScripts)) echo $extraScripts; ?>
</body>
</html>
