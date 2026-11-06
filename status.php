<?php
require_once __DIR__ . '/lib/bootstrap.php';

$cfg  = Racing::loadConfig(racing_data_dir());
$base = 'plugin.php?plugin=fpp-racing-results&page=results.php&nopage=1';
?>
<div id="racing-status">
  <h2>Racing Results</h2>
  <p>
    <button type="button" class="buttons" id="racing-refresh">Refresh now</button>
    <span id="racing-note" class="text-muted"></span>
  </p>
  <?php if (!$cfg['series']): ?>
    <p>No series selected. Pick some on the <strong>Racing Setup</strong> page.</p>
  <?php endif; ?>
  <?php foreach ($cfg['series'] as $id): ?>
    <div class="racing-series" data-series="<?= htmlspecialchars($id, ENT_QUOTES) ?>">Loading…</div>
  <?php endforeach; ?>
</div>

<script>
(function () {
  var base = <?= json_encode($base) ?>;
  var POLL_MS = 30000; // the server-side cache decides how often upstream APIs are really called

  function load(el, force) {
    var url = base + '&series=' + encodeURIComponent(el.dataset.series) + (force ? '&force=1' : '');
    fetch(url).then(function (r) { return r.text(); }).then(function (html) {
      el.innerHTML = html;
    }).catch(function () {
      el.innerHTML = '<div class="alert alert-warning">Could not load results.</div>';
    });
  }
  function loadAll(force) {
    document.querySelectorAll('.racing-series').forEach(function (el) { load(el, force); });
    document.getElementById('racing-note').textContent = ' Last checked ' + new Date().toLocaleTimeString();
  }

  document.getElementById('racing-refresh').addEventListener('click', function () { loadAll(true); });
  loadAll(false);
  setInterval(function () { loadAll(false); }, POLL_MS);
})();
</script>
