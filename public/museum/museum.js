/* Hormozgan Digital Museum — progressive enhancement, no build step. */
(function () {
  'use strict';
  var TILE = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
  var ATTR = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';
  var COLORS = { province: '#0a3742', county: '#0e5e6f', district: '#2a9d8f', city: '#b8862b', island: '#4f6d3a', village: '#9c3b28', port: '#b8862b' };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function baseMap(el, center, zoom) {
    var m = L.map(el, { scrollWheelZoom: false }).setView(center || [27.2, 56.3], zoom || 7);
    L.tileLayer(TILE, { maxZoom: 18, attribution: ATTR }).addTo(m);
    return m;
  }
  function getJSON(url) { return fetch(url, { headers: { Accept: 'application/json' } }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }); }

  // Home: interactive map with region panel
  var home = document.getElementById('map');
  if (home && window.L && home.dataset.geojson) {
    var m = baseMap(home);
    var panel = document.getElementById('region-panel');
    getJSON(home.dataset.geojson).then(function (fc) {
      if (!fc.features.length) { home.insertAdjacentHTML('afterend', '<p class="muted">هنوز مکان منتشرشده‌ای با مختصات وجود ندارد.</p>'); return; }
      var layer = L.geoJSON(fc, {
        pointToLayer: function (f, ll) {
          var t = f.properties.type;
          return L.circleMarker(ll, { radius: t === 'province' ? 9 : t === 'county' ? 7 : 5, color: '#fff', weight: 1, fillColor: COLORS[t] || '#5d6a6c', fillOpacity: .9 });
        },
        onEachFeature: function (f, l) {
          l.bindTooltip(esc(f.properties.name) + ' — ' + esc(f.properties.type_label));
          l.on('click', function () { showRegion(f.properties); });
        }
      }).addTo(m);
      m.fitBounds(layer.getBounds(), { padding: [20, 20] });
    }).catch(function () { home.insertAdjacentHTML('afterend', '<p class="muted">بارگذاری نقشه ممکن نشد.</p>'); });
    var DOMAINS = { geography: 'جغرافیا', language: 'زبان', history: 'تاریخ', culture: 'فرهنگ', nature: 'طبیعت', people: 'مردم', maritime: 'دریا', archive: 'آرشیو' };
    function showRegion(p) {
      panel.hidden = false;
      panel.innerHTML = '<h3><a href="' + esc(p.url) + '">' + esc(p.name) + '</a> <span class="pill sea">' + esc(p.type_label) + '</span></h3><p class="muted">در حال بارگذاری…</p>';
      getJSON(home.dataset.region + '/' + encodeURIComponent(p.slug)).then(function (d) {
        var html = '<h3><a href="' + esc(p.url) + '">' + esc(p.name) + '</a> <span class="pill sea">' + esc(p.type_label) + '</span></h3>';
        html += '<p class="muted">' + d.sub_places + ' زیرمجموعه مکانی</p>';
        var keys = Object.keys(d.domains || {});
        if (!keys.length) html += '<p class="muted">برای این منطقه هنوز مدخل فرهنگی/تاریخی منتشرشده‌ای وجود ندارد.</p>';
        keys.forEach(function (k) {
          html += '<h4>' + esc(DOMAINS[k] || k) + ' (' + d.counts[k] + ')</h4><p>' + d.domains[k].map(function (e) { return '<a href="' + esc(e.url) + '">' + esc(e.name) + '</a>'; }).join('، ') + '</p>';
        });
        panel.innerHTML = html;
      });
    }
  }

  // Entity page mini map
  var mini = document.getElementById('mini-map');
  if (mini && window.L) {
    var mm = baseMap(mini, [parseFloat(mini.dataset.lat), parseFloat(mini.dataset.lng)], 10);
    L.marker([parseFloat(mini.dataset.lat), parseFloat(mini.dataset.lng)]).addTo(mm).bindTooltip(mini.dataset.name);
  }

  // Dialect comparison: table + map + audio
  var cmp = document.getElementById('compare');
  if (cmp && window.L) {
    var cm = baseMap(document.getElementById('compare-map'));
    getJSON(cmp.dataset.api).then(function (d) {
      var table = document.getElementById('compare-table'), bounds = [];
      if (!d.data.length) table.insertAdjacentHTML('afterend', '<p class="muted">برای این مفهوم هنوز واژه مستندی ثبت نشده است.</p>');
      d.data.forEach(function (w) {
        var audio = w.audio.map(function (a) { return '<audio controls preload="none" src="' + esc(a.url) + '"></audio><div class="src">' + esc(a.speaker) + '</div>'; }).join('');
        table.insertAdjacentHTML('beforeend', '<tr><td><a href="' + esc(w.url) + '"><strong>' + esc(w.word) + '</strong></a></td><td>' + esc(w.transcription_fa || '') + ' <span class="ltr">' + esc(w.ipa || '') + '</span></td><td>' + esc(w.dialect || '') + '</td><td>' + esc(w.place ? w.place.name : '') + '</td><td>' + (audio || '<span class="muted">—</span>') + '</td><td class="src">' + w.sources.map(esc).join('<br>') + '</td></tr>');
        if (w.place && w.place.lat) { bounds.push([w.place.lat, w.place.lng]); L.marker([w.place.lat, w.place.lng]).addTo(cm).bindTooltip('<strong>' + esc(w.word) + '</strong> — ' + esc(w.place.name), { permanent: true }); }
      });
      if (bounds.length) cm.fitBounds(bounds, { padding: [40, 40], maxZoom: 10 });
    });
  }

  // Hormozgan through time
  var tm = document.getElementById('timemap');
  if (tm && window.L) {
    var years = [1800, 1850, 1900, 1950, 2000, new Date().getFullYear()];
    var tmap = baseMap(document.getElementById('tm-map')), current = null;
    var slider = tm.querySelector('input'), label = document.getElementById('tm-year'), count = document.getElementById('tm-count');
    function load() {
      var y = years[parseInt(slider.value, 10)];
      label.textContent = slider.value === '5' ? 'امروز' : String(y);
      getJSON(tm.dataset.api + '?year=' + y).then(function (fc) {
        if (current) tmap.removeLayer(current);
        current = L.geoJSON(fc, { onEachFeature: function (f, l) {
          var p = f.properties;
          l.bindPopup('<strong>' + esc(p.label) + '</strong>' + (p.current_name ? '<br>نام کنونی: ' + esc(p.current_name) : '') + (p.source ? '<br><span class="src">' + esc(p.source) + '</span>' : ''));
        } }).addTo(tmap);
        count.textContent = fc.features.length ? fc.features.length + ' عارضه مستند' : 'برای این سال هنوز داده مستندی ثبت نشده است';
      });
    }
    slider.addEventListener('input', load); load();
  }

  // Interactive lenj
  document.querySelectorAll('.lenj .hot').forEach(function (h) {
    function show() {
      document.querySelectorAll('.part-info').forEach(function (p) { p.hidden = true; });
      document.querySelectorAll('.lenj .hot').forEach(function (x) { x.classList.remove('on'); });
      h.classList.add('on');
      var info = document.getElementById('part-' + h.dataset.part); if (info) info.hidden = false;
      var hint = document.getElementById('part-hint'); if (hint) hint.hidden = true;
    }
    h.addEventListener('click', show);
    h.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); show(); } });
  });

  // Kids quiz: "which of these is a county of Hormozgan?" built only from published data
  var quiz = document.getElementById('kids-quiz');
  if (quiz) {
    getJSON(quiz.dataset.api).then(function (d) {
      var names = d.data.map(function (e) { return e.name.replace(/^شهرستان\s*/, ''); });
      if (names.length < 3) return;
      var answer = names[Math.floor(Math.random() * names.length)];
      quiz.innerHTML = '<h3>بازی: نام این شهرستان را پیدا کن!</h3><p style="font-size:1.6rem">' + esc(answer.split('').sort(function () { return Math.random() - .5; }).join(' ')) + '</p>' +
        '<p>' + names.slice(0).sort(function () { return Math.random() - .5; }).slice(0, 3).concat([answer]).filter(function (v, i, a) { return a.indexOf(v) === i; })
          .sort(function () { return Math.random() - .5; }).map(function (n) { return '<button class="btn alt" data-n="' + esc(n) + '">' + esc(n) + '</button>'; }).join(' ') + '</p><p id="kq-r"></p>';
      quiz.querySelectorAll('button').forEach(function (b) { b.addEventListener('click', function () {
        document.getElementById('kq-r').textContent = b.dataset.n === answer ? '👏 آفرین! درست است.' : 'دوباره امتحان کن!';
      }); });
    });
  }
})();
