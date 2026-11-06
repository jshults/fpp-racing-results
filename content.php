<?php
require_once __DIR__ . '/lib/bootstrap.php';

$cfg  = Racing::loadConfig(racing_data_dir());
$base = 'plugin.php?plugin=fpp-racing-results&page=results.php&nopage=1';
?>
<div id="racing-setup">
  <h2>Racing Results Setup</h2>

  <fieldset>
    <legend>Series to show</legend>
    <?php foreach (Racing::SERIES as $id => $def): ?>
      <div>
        <label>
          <input type="checkbox" class="racing-series-box" value="<?= htmlspecialchars($id, ENT_QUOTES) ?>"
                 <?= in_array($id, $cfg['series'], true) ? 'checked' : '' ?>>
          <?= htmlspecialchars($def['name']) ?>
          <small class="text-muted">(<?= htmlspecialchars($def['source']) ?>)</small>
        </label>
      </div>
    <?php endforeach; ?>
  </fieldset>

  <p>
    <label>Refresh every
      <input type="number" id="racing-refresh-secs" min="<?= Racing::MIN_REFRESH ?>" max="3600" step="10"
             value="<?= (int) $cfg['refreshSeconds'] ?>"> seconds
    </label>
    <small class="text-muted">(checked at most this often; live sessions use <?= Racing::LIVE_REFRESH ?>s)</small>
  </p>
  <p>
    <label>Rows to show
      <input type="number" id="racing-top-n" min="3" max="30" value="<?= (int) $cfg['topN'] ?>">
    </label>
  </p>

  <p>
    <button type="button" class="buttons" id="racing-save">Save</button>
    <span id="racing-save-msg"></span>
  </p>
</div>

<script>
(function () {
  var base = <?= json_encode($base) ?>;
  document.getElementById('racing-save').addEventListener('click', function () {
    var body = {
      series: Array.prototype.map.call(document.querySelectorAll('.racing-series-box:checked'), function (b) { return b.value; }),
      refreshSeconds: parseInt(document.getElementById('racing-refresh-secs').value, 10),
      topN: parseInt(document.getElementById('racing-top-n').value, 10)
    };
    var msg = document.getElementById('racing-save-msg');
    fetch(base + '&action=saveConfig', { method: 'POST', body: JSON.stringify(body) })
      .then(function (r) { return r.json(); })
      .then(function (j) { msg.textContent = j.error ? 'Error: ' + j.error : ' Saved.'; })
      .catch(function () { msg.textContent = ' Save failed.'; });
  });
})();
</script>
