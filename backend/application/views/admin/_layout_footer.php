<footer class="kfb-footer">
    <small>&copy; <?= date('Y') ?> Admin Portal</small>
  </footer>
</main>
<script>window.KFB_ADMIN = {
  baseUrl: <?= json_encode(base_url()) ?>,
  siteUrl: <?= json_encode(site_url()) ?>,
  uploadsUrl: <?= json_encode(base_url('uploads/vehicles/')) ?>
};</script>
<script src="<?= base_url('assets/admin/admin.js') ?>?v=<?= filemtime(FCPATH . 'assets/admin/admin.js') ?>"></script>
</body>
</html>