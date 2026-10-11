<?php $terms = isset($terms) ? $terms : ['html' => '', 'updated_at' => NULL, 'updated_by' => NULL]; ?>

<section class="kfb-card">
  <header class="kfb-card-head">
    <h2>Terms &amp; Conditions</h2>
    <?php if (!empty($terms['updated_at'])): ?>
      <small class="kfb-hint">Last saved <?= htmlspecialchars($terms['updated_at']) ?><?= !empty($terms['updated_by']) ? ' by ' . htmlspecialchars($terms['updated_by']) : '' ?></small>
    <?php endif; ?>
  </header>

  <p class="kfb-hint" style="margin:-4px 0 14px;">
    This text appears in the popup that opens from the <strong>&ldquo;Master Terms &amp; Conditions&rdquo;</strong> link on step 3 of the booking widget,
    for every company. Saving replaces the current text — there is no version history. Scripts, images and unsupported formatting are removed when you save.
  </p>

  <form method="post" action="<?= site_url('admin/terms/save') ?>" id="kfbTermsForm">
    <textarea id="kfbTermsEditor" name="html" style="width:100%;min-height:560px;"><?= htmlspecialchars($terms['html']) ?></textarea>
    <div class="kfb-form-actions" style="margin-top:14px;">
      <button type="submit" class="kfb-btn kfb-btn--primary">Save Terms &amp; Conditions</button>
    </div>
  </form>
</section>

<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/7.9.1/tinymce.min.js" referrerpolicy="origin"></script>
<script>
  (function () {
    // If the editor can't load (CDN blocked / offline) the plain textarea above still
    // works — it just takes raw HTML instead of a formatted editor.
    if (!window.tinymce) return;
    tinymce.init({
      selector: '#kfbTermsEditor',
      license_key: 'gpl',
      height: 640,
      menubar: false,
      branding: false,
      promotion: false,
      plugins: 'lists link table code autoresize',
      toolbar: 'undo redo | blocks | bold italic underline | alignleft aligncenter alignright alignjustify | bullist numlist | link table | removeformat | code',
      block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',
      autoresize_bottom_margin: 24,
      min_height: 560,
      convert_urls: false,
      link_default_target: '_blank',
      content_style: 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;font-size:15px;line-height:1.6;max-width:820px;margin:16px auto;padding:0 12px;color:#111}h2,h3,h4{margin:1.2em 0 .4em}p{margin:0 0 .9em}'
    });
  })();
</script>
