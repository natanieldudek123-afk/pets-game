  </main>
</div><!-- /layout -->

<script type="module" src="./js/topbar.js"></script>
<?php if (!empty($pageScripts)): ?>
<?php foreach ($pageScripts as $src): ?>
<script type="module" src="<?= $src ?>"></script>
<?php endforeach; ?>
<?php endif; ?>
</body>
</html>
