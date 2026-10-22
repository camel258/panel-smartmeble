/*!
 * Smartmeble – rdzeń konfiguratora mebli (katalog, budowa modelu, podgląd 3D WebGL).
 * Bez zależności. Używany przez konfigurator na stronie i podgląd w panelu.
 * Jednostki: centymetry. Oś Y w górę. Ściana A leży na z = 0, wnętrze pokoju w stronę +z.
 */
(function (global) {
  'use strict';

  /* ======================================================================
   * 1. Wykończenia
   * ==================================================================== */
  function hex(h) { h = h.replace('#', ''); return [parseInt(h.substr(0, 2), 16) / 255, parseInt(h.substr(2, 2), 16) / 255, parseInt(h.substr(4, 2), 16) / 255]; }
  // mat: 0 mat, 1 drewno, 2 połysk, 3 świecący (LED), 4 szkło/lustro
  var FINISHES = {
    front: [
      { id: 'lak_bialy_mat', name: 'Lakier biały mat', c: '#f1f1ee', m: 0 },
      { id: 'lak_bialy_pol', name: 'Lakier biały połysk', c: '#f6f6f4', m: 2 },
      { id: 'lak_kaszmir', name: 'Lakier kaszmir mat', c: '#d7cdbf', m: 0 },
      { id: 'lak_szalwia', name: 'Lakier szałwia mat', c: '#a9b39b', m: 0 },
      { id: 'lak_grafit', name: 'Lakier grafit mat', c: '#4a4d51', m: 0 },
      { id: 'lak_butelka', name: 'Lakier zieleń butelkowa', c: '#2e4a3e', m: 0 },
      { id: 'lak_czarny', name: 'Lakier czarny mat', c: '#262729', m: 0 },
      { id: 'fornir_dab', name: 'Fornir dąb naturalny', c: '#c39a6b', m: 1 },
      { id: 'fornir_orzech', name: 'Fornir orzech', c: '#6d4a33', m: 1 },
      { id: 'plyta_dab', name: 'Płyta dąb', c: '#b88e60', m: 1 },
      { id: 'plyta_biala', name: 'Płyta biała', c: '#ededea', m: 0 },
      { id: 'plyta_szara', name: 'Płyta szara', c: '#a5a7a6', m: 0 }
    ],
    body: [
      { id: 'bialy', name: 'Biały', c: '#efefec', m: 0 },
      { id: 'szary', name: 'Szary', c: '#b7b9b8', m: 0 },
      { id: 'grafit', name: 'Grafit', c: '#55585b', m: 0 },
      { id: 'czarny', name: 'Czarny', c: '#2b2b2c', m: 0 },
      { id: 'dab', name: 'Dąb', c: '#c09a6c', m: 1 }
    ],
    top: [
      { id: 'dab', name: 'Dąb (drewno lub laminat)', c: '#b7875a', m: 1 },
      { id: 'kamien_jasny', name: 'Konglomerat jasny', c: '#e6e3dc', m: 2 },
      { id: 'kamien_ciemny', name: 'Konglomerat ciemny', c: '#3b3b3e', m: 2 },
      { id: 'spiek_beton', name: 'Spiek – beton', c: '#9b9994', m: 0 },
      { id: 'bialy', name: 'Biały mat', c: '#f2f2ef', m: 0 }
    ],
    handle: [
      { id: 'gola', name: 'Bez uchwytów (frezowany profil Gola)' },
      { id: 'listwa', name: 'Uchwyty listwowe czarne' },
      { id: 'inox', name: 'Uchwyty relingowe stal' },
      { id: 'tip', name: 'Bez uchwytów (otwieranie na dotyk)' }
    ]
  };
  function finish(group, id) {
    var list = FINISHES[group];
    for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i];
    return list[0];
  }

  var COL = {
    wall: hex('#e8e6e1'), floor: hex('#d9d5cd'), plinth: hex('#3a3b3d'), inox: hex('#c9cbcd'),
    glass: hex('#1c1e21'), steel: hex('#8d9093'), black: hex('#202123'), white: hex('#f7f7f5'),
    led: hex('#fff3cf'), mirror: hex('#b9c7cf'), rail: hex('#a7a9ab'), dim: hex('#3b3f45'),
    gola: hex('#1e1f21'), warn: hex('#e5484d'), sel: hex('#a0c525'), shadow: hex('#c9c4ba')
  };

  /* ======================================================================
   * 2. Typy mebli, układy i katalog modułów
   * ==================================================================== */
  var TYPES = {
    kuchnia: {
      name: 'Kuchnia', layouts: ['I', 'L', 'U', 'II'],
      rows: [{ id: 'low', name: 'Szafki dolne i słupki' }, { id: 'up', name: 'Szafki górne' }],
      corner: { low: 60, up: 35 }, height: 260
    },
    szafa: {
      name: 'Szafa', layouts: ['I'],
      rows: [{ id: 'low', name: 'Moduły szafy' }],
      corner: { low: 60, up: 0 }, height: 250
    },
    garderoba: {
      name: 'Garderoba', layouts: ['I', 'L', 'U'],
      rows: [{ id: 'low', name: 'Moduły garderoby' }],
      corner: { low: 50, up: 0 }, height: 250
    },
    lazienka: {
      name: 'Meble łazienkowe', layouts: ['I', 'L'],
      rows: [{ id: 'low', name: 'Szafki stojące i podumywalkowe' }, { id: 'up', name: 'Szafki wiszące i lustra' }],
      corner: { low: 50, up: 20 }, height: 260
    }
  };
  var LAYOUTS = {
    I: { name: 'Jedna ściana', walls: ['A'] },
    L: { name: 'Narożna (L)', walls: ['A', 'B'] },
    U: { name: 'Trzy ściany (U)', walls: ['B', 'A', 'C'] },
    II: { name: 'Dwie ściany naprzeciw', walls: ['A', 'D'] }
  };

  // --- Wymiary kuchni ---
  var K = { plinth: 10, baseTop: 82, topT: 4, baseD: 56, frontT: 2, topD: 62, upY: 140, upD: 33, gap: 0.3 };

  // Pomocnik: frontów siatka -> lista paneli
  function doors(n, y0, y1, w) { var a = [], dw = w / n; for (var i = 0; i < n; i++) a.push({ s0: i * dw, s1: (i + 1) * dw, y0: y0, y1: y1, k: 'door', hinge: n === 1 ? 'L' : (i === 0 ? 'L' : 'R') }); return a; }
  function drawers(heights, y0, y1, w) { // heights: proporcje od dołu
    var sum = heights.reduce(function (a, b) { return a + b; }, 0), a = [], y = y0;
    heights.forEach(function (h) { var hh = (y1 - y0) * h / sum; a.push({ s0: 0, s1: w, y0: y, y1: y + hh, k: 'drawer' }); y += hh; });
    return a;
  }
  function nDoors(w) { return w > 60 ? 2 : 1; }

  var CATALOG = [
    // ---------------- KUCHNIA: dolne ----------------
    { id: 'kd_drzwi', t: 'kuchnia', row: 'low', name: 'Szafka z drzwiami', widths: [30, 40, 45, 50, 60, 80, 90], w: 60, cat: 'Szafki dolne', build: function (c) { kBase(c, doors(nDoors(c.w), K.plinth, K.baseTop, c.w)); } },
    { id: 'kd_szuflady', t: 'kuchnia', row: 'low', name: 'Szafka z 3 szufladami', widths: [40, 50, 60, 80, 90], w: 60, cat: 'Szafki dolne', build: function (c) { kBase(c, drawers([1.6, 1.6, 1], K.plinth, K.baseTop, c.w).reverse()); } },
    { id: 'kd_zlew', t: 'kuchnia', row: 'low', name: 'Szafka zlewowa', widths: [60, 80, 90], w: 80, cat: 'Szafki dolne', build: function (c) { kBase(c, doors(nDoors(c.w), K.plinth, K.baseTop, c.w)); sink(c); } },
    { id: 'kd_plyta', t: 'kuchnia', row: 'low', name: 'Szafka pod płytę grzewczą', widths: [60, 80, 90], w: 60, cat: 'Szafki dolne', build: function (c) { kBase(c, drawers([1.6, 1.6, 1], K.plinth, K.baseTop, c.w).reverse()); hob(c); } },
    { id: 'kd_piekarnik', t: 'kuchnia', row: 'low', name: 'Szafka pod piekarnik', widths: [60], w: 60, cat: 'Szafki dolne', build: function (c) { kBase(c, [{ s0: 0, s1: c.w, y0: K.plinth, y1: 24, k: 'drawer' }, { s0: 0, s1: c.w, y0: 24, y1: K.baseTop, k: 'oven' }]); } },
    { id: 'kd_zmywarka', t: 'kuchnia', row: 'low', name: 'Zmywarka w zabudowie', widths: [45, 60], w: 60, cat: 'Szafki dolne', build: function (c) { kBase(c, [{ s0: 0, s1: c.w, y0: K.plinth, y1: K.baseTop, k: 'panel' }]); } },
    { id: 'kd_cargo', t: 'kuchnia', row: 'low', name: 'Cargo (wąska wysuwana)', widths: [15, 20, 30], w: 20, cat: 'Szafki dolne', build: function (c) { kBase(c, [{ s0: 0, s1: c.w, y0: K.plinth, y1: K.baseTop, k: 'drawer' }]); } },
    { id: 'kd_narozna', t: 'kuchnia', row: 'low', name: 'Szafka narożna', widths: [90, 100, 110], w: 100, cat: 'Szafki dolne', build: function (c) { kBase(c, [{ s0: c.w - 45, s1: c.w, y0: K.plinth, y1: K.baseTop, k: 'door', hinge: 'R' }, { s0: 0, s1: c.w - 45, y0: K.plinth, y1: K.baseTop, k: 'blind' }]); } },
    // ---------------- KUCHNIA: słupki ----------------
    { id: 'ks_lodowka', t: 'kuchnia', row: 'low', tall: true, name: 'Słupek z lodówką w zabudowie', widths: [60], w: 60, cat: 'Słupki', build: function (c) { var top = upTop(c); kTall(c, [{ s0: 0, s1: c.w, y0: K.plinth, y1: 92, k: 'door', hinge: 'R' }, { s0: 0, s1: c.w, y0: 92, y1: 182, k: 'door', hinge: 'R' }, { s0: 0, s1: c.w, y0: 182, y1: top, k: 'flap' }]); } },
    { id: 'ks_piekarnik', t: 'kuchnia', row: 'low', tall: true, name: 'Słupek z piekarnikiem i mikrofalą', widths: [60], w: 60, cat: 'Słupki', build: function (c) { var top = upTop(c); kTall(c, [{ s0: 0, s1: c.w, y0: K.plinth, y1: 50, k: 'drawer' }, { s0: 0, s1: c.w, y0: 50, y1: 110, k: 'oven' }, { s0: 0, s1: c.w, y0: 110, y1: 148, k: 'micro' }, { s0: 0, s1: c.w, y0: 148, y1: top, k: 'door', hinge: 'L' }]); } },
    { id: 'ks_spizarnia', t: 'kuchnia', row: 'low', tall: true, name: 'Słupek – spiżarnia', widths: [30, 40, 45, 50, 60], w: 50, cat: 'Słupki', build: function (c) { var top = upTop(c); kTall(c, [{ s0: 0, s1: c.w, y0: K.plinth, y1: 140, k: 'door', hinge: 'L' }, { s0: 0, s1: c.w, y0: 140, y1: top, k: 'door', hinge: 'L' }]); } },
    // ---------------- KUCHNIA: górne ----------------
    { id: 'kg_drzwi', t: 'kuchnia', row: 'up', name: 'Szafka górna z drzwiami', widths: [30, 40, 45, 50, 60, 80, 90], w: 60, cat: 'Szafki górne', build: function (c) { kUp(c, doors(nDoors(c.w), K.upY, upTop(c), c.w)); } },
    { id: 'kg_klapa', t: 'kuchnia', row: 'up', name: 'Szafka górna z klapą', widths: [60, 80, 90, 100], w: 80, cat: 'Szafki górne', build: function (c) { var t = upTop(c), m = (K.upY + t) / 2; kUp(c, [{ s0: 0, s1: c.w, y0: K.upY, y1: m, k: 'flap' }, { s0: 0, s1: c.w, y0: m, y1: t, k: 'flap' }]); } },
    { id: 'kg_okap', t: 'kuchnia', row: 'up', name: 'Szafka nad okapem', widths: [60, 90], w: 60, cat: 'Szafki górne', build: function (c) { var t = upTop(c); kUp(c, [{ s0: 0, s1: c.w, y0: t - 36, y1: t, k: 'flap' }], t - 36); c.box(2, c.w - 2, K.upY - 2, K.upY + 6, 2, 48, COL.inox, 2, { edges: true }); c.box(c.w / 2 - 15, c.w / 2 + 15, K.upY + 6, t - 36, 4, 30, COL.inox, 2, { edges: true }); } },
    { id: 'kg_otwarta', t: 'kuchnia', row: 'up', name: 'Półka otwarta', widths: [30, 40, 60, 80], w: 60, cat: 'Szafki górne', build: function (c) { var t = upTop(c); openBox(c, K.upY, t, 0, K.upD + K.frontT, c.f.body, [K.upY + (t - K.upY) / 2]); } },

    // ---------------- SZAFA ----------------
    { id: 'sz_polki', t: 'szafa', row: 'low', name: 'Półki', widths: [40, 50, 60, 80, 100], w: 60, cat: 'Moduły', build: function (c) { wardrobeModule(c, 'polki'); } },
    { id: 'sz_drazek', t: 'szafa', row: 'low', name: 'Drążek + półka', widths: [60, 80, 100, 120], w: 100, cat: 'Moduły', build: function (c) { wardrobeModule(c, 'drazek'); } },
    { id: 'sz_drazek2', t: 'szafa', row: 'low', name: 'Dwa drążki (krótkie ubrania)', widths: [60, 80, 100], w: 80, cat: 'Moduły', build: function (c) { wardrobeModule(c, 'drazek2'); } },
    { id: 'sz_szuflady', t: 'szafa', row: 'low', name: 'Szuflady + półki', widths: [50, 60, 80], w: 60, cat: 'Moduły', build: function (c) { wardrobeModule(c, 'szuflady'); } },
    { id: 'sz_buty', t: 'szafa', row: 'low', name: 'Półki na buty', widths: [50, 60, 80], w: 60, cat: 'Moduły', build: function (c) { wardrobeModule(c, 'buty'); } },

    // ---------------- GARDEROBA ----------------
    { id: 'ga_polki', t: 'garderoba', row: 'low', name: 'Regał z półkami', widths: [40, 50, 60, 80, 100], w: 60, cat: 'Moduły', build: function (c) { wardrobeModule(c, 'polki'); } },
    { id: 'ga_drazek', t: 'garderoba', row: 'low', name: 'Drążek + półki', widths: [60, 80, 100, 120], w: 100, cat: 'Moduły', build: function (c) { wardrobeModule(c, 'drazek'); } },
    { id: 'ga_drazek2', t: 'garderoba', row: 'low', name: 'Dwa drążki', widths: [60, 80, 100], w: 80, cat: 'Moduły', build: function (c) { wardrobeModule(c, 'drazek2'); } },
    { id: 'ga_szuflady', t: 'garderoba', row: 'low', name: 'Komoda z szufladami', widths: [50, 60, 80, 100], w: 80, cat: 'Moduły', build: function (c) { wardrobeModule(c, 'szuflady'); } },
    { id: 'ga_buty', t: 'garderoba', row: 'low', name: 'Półki na buty', widths: [50, 60, 80], w: 60, cat: 'Moduły', build: function (c) { wardrobeModule(c, 'buty'); } },
    { id: 'ga_lustro', t: 'garderoba', row: 'low', name: 'Lustro', widths: [40, 50, 60], w: 50, cat: 'Moduły', build: function (c) { c.box(2, c.w - 2, 20, Math.min(c.H - 10, 200), 0, 2.5, COL.mirror, 4, { edges: true }); } },

    // ---------------- ŁAZIENKA ----------------
    { id: 'la_umywalkowa', t: 'lazienka', row: 'low', name: 'Szafka podumywalkowa wisząca', widths: [60, 80, 100, 120, 140], w: 80, cat: 'Szafki dolne', build: function (c) { bathVanity(c); } },
    { id: 'la_komoda', t: 'lazienka', row: 'low', name: 'Komoda wisząca z szufladami', widths: [40, 60, 80], w: 60, cat: 'Szafki dolne', build: function (c) { var d = 46; c.box(0, c.w, 35, 80, 0, d - 2, c.f.body.col, c.f.body.m, { edges: true, uid: c.uid }); frontSet(c, drawers([1, 1], 35, 80, c.w), d - 2, d, c.f.low, 'low'); } },
    { id: 'la_slupek', t: 'lazienka', row: 'low', tall: true, name: 'Słupek wiszący', widths: [30, 40, 50], w: 40, cat: 'Szafki dolne', build: function (c) { var d = 33; c.box(0, c.w, 25, 185, 0, d - 2, c.f.body.col, c.f.body.m, { edges: true, uid: c.uid }); frontSet(c, doors(1, 25, 185, c.w), d - 2, d, c.f.low, 'tall'); } },
    { id: 'la_pralka', t: 'lazienka', row: 'low', name: 'Zabudowa pralki', widths: [65, 70], w: 65, cat: 'Szafki dolne', build: function (c) { var d = 62; openBox(c, 0, 95, 0, d, c.f.body, []); c.box(4, c.w - 4, 1.8, 86, 4, d - 3, COL.white, 2, { edges: true }); c.box(c.w / 2 - 15, c.w / 2 + 15, 30, 62, d - 3, d - 2, COL.glass, 4, {}); } },
    { id: 'la_wiszaca', t: 'lazienka', row: 'up', name: 'Szafka wisząca', widths: [40, 60, 80, 100], w: 60, cat: 'Szafki górne', build: function (c) { var d = 18; c.box(0, c.w, 120, 185, 0, d - 2, c.f.body.col, c.f.body.m, { edges: true, uid: c.uid }); frontSet(c, doors(nDoors(c.w), 120, 185, c.w), d - 2, d, c.f.up, 'up'); } },
    { id: 'la_lustro', t: 'lazienka', row: 'up', name: 'Lustro z oświetleniem', widths: [60, 80, 100, 120, 140], w: 80, cat: 'Szafki górne', build: function (c) { c.box(0, c.w, 105, 180, 0, 3, COL.mirror, 4, { edges: true, uid: c.uid }); if (c.f.led) c.box(2, c.w - 2, 103.5, 105, 1, 3, COL.led, 3, {}); } }
  ];
  var CAT = {}; CATALOG.forEach(function (m) { CAT[m.id] = m; });

  function upTop(c) { return K.upY + (c.p.finish.upH || 72); }

  /* --- Budowniczowie modułów (współrzędne lokalne: s wzdłuż ściany, y w górę, d od ściany) --- */
  function kBase(c, panels) {
    var d = c.depth || (K.baseD + K.frontT);
    c.box(0.4, c.w - 0.4, 0, K.plinth, 0, d - 6, COL.plinth, 0, {});
    c.box(0, c.w, K.plinth, K.baseTop, 0, d - K.frontT, c.f.body.col, c.f.body.m, { edges: true, uid: c.uid });
    frontSet(c, panels, d - K.frontT, d, c.f.low, 'low');
    if (!c.noTop) { var a0 = Math.max(0, (c.clipTop || 0) - (c.s0abs || 0)); if (a0 < c.w) c.box(a0, c.w, K.baseTop, K.baseTop + K.topT, 0, (c.topD || K.topD), c.f.top.col, c.f.top.m, { edges: true, tag: 'top' }); }
  }
  function kTall(c, panels) {
    var d = K.baseD + K.frontT, top = upTop(c);
    c.box(0.4, c.w - 0.4, 0, K.plinth, 0, d - 6, COL.plinth, 0, {});
    c.box(0, c.w, K.plinth, top, 0, d - K.frontT, c.f.body.col, c.f.body.m, { edges: true, uid: c.uid });
    frontSet(c, panels, d - K.frontT, d, c.f.low, 'tall');
  }
  function kUp(c, panels, yBodyFrom) {
    var top = upTop(c), y0 = yBodyFrom || K.upY;
    c.box(0, c.w, y0, top, 0, K.upD, c.f.body.col, c.f.body.m, { edges: true, uid: c.uid });
    frontSet(c, panels, K.upD, K.upD + K.frontT, c.f.up, 'up');
    if (c.f.led && !yBodyFrom) c.box(1.5, c.w - 1.5, y0 - 0.6, y0, 4, 7, COL.led, 3, {});
  }
  function sink(c) {
    var w = Math.min(c.w - 16, 70), s0 = (c.w - w) / 2, y = K.baseTop + K.topT;
    c.box(s0, s0 + w, y - 0.2, y + 0.15, 10, 52, COL.inox, 2, { edges: true });
    c.box(s0 + 3, s0 + w - 3, y + 0.15, y + 0.3, 13, 49, COL.steel, 2, {});
    c.box(c.w / 2 - 2, c.w / 2 + 2, y, y + 30, 4, 8, COL.inox, 2, { edges: true });
    c.box(c.w / 2 - 1.5, c.w / 2 + 1.5, y + 27, y + 30, 8, 22, COL.inox, 2, {});
  }
  function hob(c) {
    var w = Math.min(c.w - 4, 76), s0 = (c.w - w) / 2, y = K.baseTop + K.topT;
    c.box(s0, s0 + w, y, y + 0.6, 6, 56, COL.glass, 4, { edges: true });
  }
  function openBox(c, y0, y1, d0, d1, body, shelves) {
    var t = 1.8, col = body.col, m = body.m;
    c.box(0, t, y0, y1, d0, d1, col, m, { edges: true, uid: c.uid });
    c.box(c.w - t, c.w, y0, y1, d0, d1, col, m, { edges: true, uid: c.uid });
    c.box(t, c.w - t, y1 - t, y1, d0, d1, col, m, { edges: true, uid: c.uid });
    c.box(t, c.w - t, y0, y0 + t, d0, d1, col, m, { edges: true, uid: c.uid });
    c.box(t, c.w - t, y0 + t, y1 - t, d0, d0 + 0.8, col, m, { uid: c.uid });
    (shelves || []).forEach(function (y) { c.box(t, c.w - t, y - t / 2, y + t / 2, d0 + 1, d1 - 1, col, m, { edges: true, uid: c.uid }); });
  }
  function rail(c, y) { c.box(2, c.w - 2, y - 1, y + 1, c.D / 2 - 1, c.D / 2 + 1, COL.rail, 2, { edges: true }); }
  function clothes(c, y0, y1) { // sylwetki ubrań na drążku
    var n = Math.max(2, Math.floor((c.w - 8) / 9)), dw = (c.w - 8) / n;
    for (var i = 0; i < n; i++) {
      var g = 0.55 + 0.25 * ((i * 37) % 7) / 7;
      c.box(4 + i * dw + 1.5, 4 + (i + 1) * dw - 1.5, y0 + ((i * 13) % 5) * 3, y1 - 3, c.D / 2 - 18, c.D / 2 + 18, [g * 0.62, g * 0.66, g * 0.72], 0, {});
    }
  }
  function wardrobeModule(c, kind) {
    var H = c.H, D = c.D, closed = c.p.type === 'szafa' && c.p.finish.doors !== 'brak' && !c.p.finish.showInterior;
    var plinth = c.p.type === 'szafa' ? 8 : 0;
    openBox(c, plinth, H, 0, D - (c.p.type === 'szafa' ? 4.5 : 0), c.f.body, []);
    if (plinth) c.box(0.4, c.w - 0.4, 0, plinth, 0, D - 8, COL.plinth, 0, {});
    var t = 1.8, y0 = plinth + t, top = H - t;
    var sh = function (y) { c.box(t, c.w - t, y - t / 2, y + t / 2, 1, D - 6, c.f.body.col, c.f.body.m, { edges: true, uid: c.uid }); };
    if (kind === 'polki') { var n = Math.max(3, Math.round((top - y0) / 36)); for (var i = 1; i < n; i++) sh(y0 + (top - y0) * i / n); }
    if (kind === 'drazek') { sh(top - 38); rail(c, top - 46); if (!closed) clothes(c, Math.max(y0 + 10, top - 150), top - 46); }
    if (kind === 'drazek2') { var mid = y0 + (top - y0) / 2; sh(top - 38); rail(c, top - 46); sh(mid); rail(c, mid - 8); if (!closed) { clothes(c, mid + 10, top - 46); clothes(c, y0 + 15, mid - 8); } }
    if (kind === 'szuflady') {
      var dh = 22, k;
      for (k = 0; k < 4; k++) c.box(t + 0.3, c.w - t - 0.3, y0 + k * dh + 0.3, y0 + (k + 1) * dh - 0.3, 3, D - 6, c.f.low.col, c.f.low.m, { edges: true });
      var yy = y0 + 4 * dh; sh(yy); var m = Math.max(2, Math.round((top - yy) / 38)); for (k = 1; k < m; k++) sh(yy + (top - yy) * k / m);
    }
    if (kind === 'buty') { var b = Math.max(4, Math.round((top - y0) / 22)); for (var j = 1; j < b; j++) sh(y0 + (top - y0) * j / b); }
  }
  function bathVanity(c) {
    var d = 46, y0 = 35, y1 = 82;
    c.box(0, c.w, y0, y1, 0, d - 2, c.f.body.col, c.f.body.m, { edges: true, uid: c.uid });
    frontSet(c, drawers([1, 1], y0, y1, c.w), d - 2, d, c.f.low, 'low');
    c.box(0, c.w, y1, y1 + 3, 0, d + 1, c.f.top.col, c.f.top.m, { edges: true, tag: 'top' });
    var bw = Math.min(46, c.w - 10), s0 = (c.w - bw) / 2;
    c.box(s0, s0 + bw, y1 + 3, y1 + 15, 8, 42, COL.white, 2, { edges: true });
    c.box(c.w / 2 - 1.5, c.w / 2 + 1.5, 95, 125, 0, 2, COL.inox, 2, {});
    c.box(c.w / 2 - 1.2, c.w / 2 + 1.2, 121, 124, 2, 14, COL.inox, 2, {});
  }

  /* Fronty + uchwyty */
  function frontSet(c, panels, d0, d1, fin, row) {
    var g = K.gap, hs = c.p.finish.handle || 'gola';
    panels.forEach(function (p) {
      var s0 = p.s0 + g, s1 = p.s1 - g, y0 = p.y0 + g, y1 = p.y1 - g;
      if (p.k === 'blind') { c.box(s0, s1, y0, y1, d0, d1, fin.col, fin.m, { edges: true, uid: c.uid, front: true }); return; }
      if (p.k === 'oven' || p.k === 'micro') {
        c.box(s0, s1, y0, y1, d0, d1, COL.glass, 4, { edges: true, uid: c.uid });
        c.box(s0 + 3, s1 - 3, y1 - 6, y1 - 4.5, d1, d1 + 1, COL.inox, 2, {});
        if (p.k === 'oven') c.box(s0 + 4, s1 - 4, y0 + 4, y1 - 12, d1, d1 + 0.2, [0.12, 0.13, 0.15], 4, {});
        return;
      }
      if (hs === 'gola' && row !== 'up' && (p.k === 'drawer' || p.k === 'door' || p.k === 'panel')) {
        y1 -= 2.2; // frezowany profil nad frontem
        c.box(s0, s1, y1, y1 + 2.2, d0 - 2, d0 - 1.5, COL.gola, 0, {});
      }
      c.box(s0, s1, y0, y1, d0, d1, fin.col, fin.m, { edges: true, uid: c.uid, front: true });
      if (hs === 'gola' || hs === 'tip') return;
      var hc = hs === 'inox' ? COL.inox : COL.black, hm = hs === 'inox' ? 2 : 0, hd = hs === 'inox' ? 3 : 1.6;
      var len = Math.min(p.k === 'drawer' ? (s1 - s0) * 0.5 : 14, 40);
      if (p.k === 'drawer' || p.k === 'flap' || p.k === 'panel') {
        var mid = (s0 + s1) / 2, yh = (p.k === 'flap' && row === 'up') ? y0 + 3 : y1 - 4;
        c.box(mid - len / 2, mid + len / 2, yh - 0.8, yh + 0.8, d1, d1 + hd, hc, hm, {});
      } else if (p.k === 'door') {
        var sx = p.hinge === 'R' ? s0 + 3 : s1 - 4.6;
        var yc = row === 'up' ? y0 + 10 : (row === 'tall' ? Math.min(y0 + (y1 - y0) / 2, 110) : y1 - 12);
        c.box(sx, sx + 1.6, yc - len / 2, yc + len / 2, d1, d1 + hd, hc, hm, {});
      }
    });
  }

  /* ======================================================================
   * 3. Projekt domyślny i budowa modelu
   * ==================================================================== */
  var uidSeq = 1;
  function newUid() { return 'm' + (Date.now() % 1e7).toString(36) + (uidSeq++).toString(36); }
  function item(mod, w) { return { uid: newUid(), mod: mod, w: w || CAT[mod].w }; }

  function defaultProject(type) {
    var p = {
      v: 1, type: type, layout: 'I', height: TYPES[type].height,
      walls: { A: 300, B: 250, C: 250, D: 300 }, roomDepth: 320,
      island: { on: false, w: 180, d: 90 },
      runs: { A: { low: [], up: [] }, B: { low: [], up: [] }, C: { low: [], up: [] }, D: { low: [], up: [] } },
      finish: { low: 'lak_bialy_mat', up: 'lak_bialy_mat', body: 'bialy', top: 'dab', handle: 'gola', led: true, upH: 72, doors: 'przesuwne', doorCount: 2, showInterior: false, depth: 60 },
      agd: false
    };
    if (type === 'kuchnia') {
      p.walls.A = 320;
      p.runs.A.low = [item('ks_lodowka'), item('kd_szuflady', 50), item('kd_zlew', 80), item('kd_zmywarka', 60), item('kd_plyta', 60)];
      p.runs.A.up = [item('kg_drzwi', 50), item('kg_klapa', 80), item('kg_drzwi', 60), item('kg_okap', 60)];
    }
    if (type === 'szafa') {
      p.walls.A = 240; p.height = 250; p.finish.low = 'plyta_dab'; p.finish.depth = 62;
      p.runs.A.low = [item('sz_drazek', 100), item('sz_szuflady', 60), item('sz_polki', 80)];
    }
    if (type === 'garderoba') {
      p.layout = 'L'; p.walls.A = 260; p.walls.B = 200; p.height = 250; p.finish.body = 'bialy'; p.finish.low = 'plyta_dab'; p.finish.depth = 55;
      p.runs.A.low = [item('ga_drazek', 100), item('ga_szuflady', 80), item('ga_drazek2', 80)];
      p.runs.B.low = [item('ga_buty', 60), item('ga_polki', 60)];
    }
    if (type === 'lazienka') {
      p.walls.A = 240; p.finish.low = 'fornir_dab'; p.finish.body = 'bialy'; p.finish.top = 'kamien_jasny'; p.finish.up = 'lak_bialy_mat'; p.finish.handle = 'tip';
      p.runs.A.low = [item('la_slupek', 40), item('la_umywalkowa', 120)];
      p.runs.A.up = [item('la_lustro', 100)];
    }
    return p;
  }

  function wallDef(p, key) {
    var A = +p.walls.A || 300;
    switch (key) {
      case 'A': return { key: 'A', o: [0, 0], u: [1, 0], n: [0, 1], L: A };
      case 'B': return { key: 'B', o: [0, 0], u: [0, 1], n: [1, 0], L: +p.walls.B || 250 };
      case 'C': return { key: 'C', o: [A, 0], u: [0, 1], n: [-1, 0], L: +p.walls.C || 250 };
      case 'D': return { key: 'D', o: [0, +p.roomDepth || 320], u: [1, 0], n: [0, -1], L: +p.walls.D || A };
    }
  }
  function wallsOf(p) {
    var keys = (LAYOUTS[p.layout] || LAYOUTS.I).walls;
    if (TYPES[p.type].layouts.indexOf(p.layout) < 0) keys = LAYOUTS.I.walls;
    return keys.map(function (k) { return wallDef(p, k); });
  }
  function startOffset(p, wall, row) {
    if ((wall.key === 'B' || wall.key === 'C') && (p.layout === 'L' || p.layout === 'U')) {
      if (p.type === 'garderoba') return +p.finish.depth || 50;
      return TYPES[p.type].corner[row] || 0;
    }
    return 0;
  }

  /** Rozmieszcza moduły w rzędzie: zwraca [{it, s0, s1, over}] */
  function layoutRow(p, wall, row) {
    var list = (p.runs[wall.key] && p.runs[wall.key][row]) || [];
    var off = startOffset(p, wall, row), out = [], s = off, blocked = [];
    if (row === 'up') {
      layoutRow(p, wall, 'low').forEach(function (r) { if (CAT[r.it.mod] && CAT[r.it.mod].tall) blocked.push([r.s0, r.s1]); });
    }
    list.forEach(function (it) {
      if (!CAT[it.mod]) return;
      var w = +it.w || CAT[it.mod].w;
      for (var k = 0; k < blocked.length; k++) if (s < blocked[k][1] && s + w > blocked[k][0]) s = blocked[k][1];
      out.push({ it: it, s0: s, s1: s + w, over: s + w > wall.L + 0.01 });
      s += w;
    });
    return out;
  }

  function buildModel(p, opts) {
    opts = opts || {};
    var boxes = [], walls = [], dims = [], warnings = [], summary = [];
    var H = +p.height || 260;
    var fin = {
      low: Object.assign({ col: hex(finish('front', p.finish.low).c) }, { m: finish('front', p.finish.low).m }),
      up: Object.assign({ col: hex(finish('front', p.finish.up).c) }, { m: finish('front', p.finish.up).m }),
      body: { col: hex(finish('body', p.finish.body).c), m: finish('body', p.finish.body).m },
      top: { col: hex(finish('top', p.finish.top).c), m: finish('top', p.finish.top).m },
      led: !!p.finish.led
    };
    var wl = wallsOf(p);
    var maxX = 0, maxZ = 0;

    function makeCtx(wall, s0, w, it, extra) {
      var sel = opts.selected && it && opts.selected === it.uid;
      var ctx = {
        w: w, p: p, f: fin, uid: it ? it.uid : null, H: H, D: +p.finish.depth || 60,
        box: function (a0, a1, y0, y1, d0, d1, col, mat, o) {
          o = o || {};
          var P = function (s, d) { return [wall.o[0] + wall.u[0] * (s0 + s) + wall.n[0] * d, wall.o[1] + wall.u[1] * (s0 + s) + wall.n[1] * d]; };
          var q1 = P(a0, d0), q2 = P(a1, d1);
          var c = col;
          if (sel && (o.front || o.uid)) c = mix(col, COL.sel, o.front ? 0.45 : 0.25);
          if (extra && extra.over && (o.front || o.uid)) c = mix(col, COL.warn, 0.55);
          boxes.push({
            min: [Math.min(q1[0], q2[0]), y0, Math.min(q1[1], q2[1])],
            max: [Math.max(q1[0], q2[0]), y1, Math.max(q1[1], q2[1])],
            c: c, m: mat || 0, e: !!o.edges, uid: o.uid || null, tag: o.tag || null, wall: wall.key
          });
        }
      };
      if (extra) for (var k in extra) ctx[k] = extra[k];
      return ctx;
    }

    var rows = TYPES[p.type].rows.map(function (r) { return r.id; });
    wl.forEach(function (wall) {
      var used = {};
      rows.forEach(function (row) {
        var placed = layoutRow(p, wall, row), last = 0;
        placed.forEach(function (r) {
          var m = CAT[r.it.mod];
          m.build(makeCtx(wall, r.s0, r.s1 - r.s0, r.it, { over: r.over, s0abs: r.s0, clipTop: ((wall.key === 'B' || wall.key === 'C') && (p.layout === 'L' || p.layout === 'U')) ? K.topD : 0 }));
          last = Math.max(last, r.s1);
          summary.push({ wall: wall.key, row: row, name: m.name, w: r.s1 - r.s0, mod: m.id, uid: r.it.uid });
        });
        used[row] = last;
        var avail = wall.L;
        if (last > avail + 0.01) warnings.push({ wall: wall.key, row: row, text: 'Ściana ' + wall.key + ', ' + rowName(p.type, row).toLowerCase() + ': moduły wychodzą ' + Math.round(last - avail) + ' cm poza ścianę.' });
      });
      // ściana pokoju (cienka bryła za ścianą)
      var t = 8, a = wall.o, e = [wall.o[0] + wall.u[0] * wall.L, wall.o[1] + wall.u[1] * wall.L];
      var back = [-wall.n[0] * t, -wall.n[1] * t];
      var ext = [wall.u[0] * t, wall.u[1] * t];
      var x0 = Math.min(a[0] - ext[0], e[0] + ext[0], a[0] + back[0], e[0] + back[0]), x1 = Math.max(a[0] - ext[0], e[0] + ext[0], a[0] + back[0], e[0] + back[0]);
      var z0 = Math.min(a[1] - ext[1], e[1] + ext[1], a[1] + back[1], e[1] + back[1]), z1 = Math.max(a[1] - ext[1], e[1] + ext[1], a[1] + back[1], e[1] + back[1]);
      if (wall.n[0] !== 0) { x0 = wall.n[0] > 0 ? a[0] - t : a[0]; x1 = wall.n[0] > 0 ? a[0] : a[0] + t; }
      if (wall.n[1] !== 0) { z0 = wall.n[1] > 0 ? a[1] - t : a[1]; z1 = wall.n[1] > 0 ? a[1] : a[1] + t; }
      walls.push({ min: [x0, 0, z0], max: [x1, H, z1], n: wall.n, c: COL.wall, key: wall.key, center: [(a[0] + e[0]) / 2, H / 2, (a[1] + e[1]) / 2] });
      maxX = Math.max(maxX, a[0], e[0]); maxZ = Math.max(maxZ, a[1], e[1]);
      // wymiar ściany
      var lift = H + 6;
      dims.push({ a: [a[0] + wall.n[0] * 2, lift, a[1] + wall.n[1] * 2], b: [e[0] + wall.n[0] * 2, lift, e[1] + wall.n[1] * 2], text: wall.key + ': ' + Math.round(wall.L) + ' cm', wall: wall.key });
    });

    // wyspa (kuchnia)
    if (p.type === 'kuchnia' && p.island && p.island.on) {
      var iw = +p.island.w || 180, idp = Math.max(60, +p.island.d || 90), A = +p.walls.A;
      var zFront = K.topD + 110, xs = Math.max(20, (A - iw) / 2);
      var iwall = { key: 'W', o: [xs + iw, zFront + idp], u: [-1, 0], n: [0, -1], L: iw };
      var n = Math.max(1, Math.round(iw / 60)), mw = iw / n;
      for (var i = 0; i < n; i++) {
        kBase(makeCtx(iwall, i * mw, mw, null, { depth: idp, noTop: true }), drawers([1.6, 1.6, 1], K.plinth, K.baseTop, mw).reverse());
      }
      // nadpisz blaty modułów wspólnym blatem z nawisem
      boxes.push({ min: [xs - 2, K.baseTop, zFront - 3], max: [xs + iw + 2, K.baseTop + K.topT, zFront + idp + 25], c: fin.top.col, m: fin.top.m, e: true });
      summary.push({ wall: 'W', row: 'low', name: 'Wyspa ' + iw + ' × ' + idp + ' cm', w: iw, mod: 'wyspa' });
      maxZ = Math.max(maxZ, zFront + idp + 25);
      dims.push({ a: [xs, K.baseTop + 10, zFront + idp + 28], b: [xs + iw, K.baseTop + 10, zFront + idp + 28], text: 'wyspa ' + iw + ' cm', wall: 'W' });
    }

    // szafa – drzwi przesuwne na całą szerokość
    if (p.type === 'szafa' && p.finish.doors === 'przesuwne' && !p.finish.showInterior) {
      var Wd = +p.walls.A, Dd = +p.finish.depth || 60, nd = Math.max(2, Math.min(5, +p.finish.doorCount || 2));
      var pw = Wd / nd, fl = fin.low;
      for (var j = 0; j < nd; j++) {
        var zz = (j % 2 === 0) ? Dd - 2 : Dd - 4.2;
        boxes.push({ min: [j * pw - (j > 0 ? 1.5 : 0), 9, zz], max: [(j + 1) * pw + (j < nd - 1 ? 1.5 : 0), H - 4, zz + 1.8], c: fl.col, m: fl.m, e: true });
        boxes.push({ min: [j * pw + 1, 9.5, zz + 1.8], max: [j * pw + 1.6, H - 4.5, zz + 2.1], c: COL.steel, m: 2, e: false });
      }
      boxes.push({ min: [0, H - 4, Dd - 5], max: [Wd, H, Dd], c: COL.steel, m: 2, e: true });
    }
    if (p.type === 'szafa' && p.finish.doors === 'uchylne' && !p.finish.showInterior) {
      var placedS = layoutRow(p, wallDef(p, 'A'), 'low'), D2 = +p.finish.depth || 60;
      placedS.forEach(function (r) {
        var w = r.s1 - r.s0, c = makeCtx(wallDef(p, 'A'), r.s0, w, r.it, { over: r.over });
        frontSet(c, doors(nDoors(w), 8, H, w), D2 - 2.2, D2 - 0.4, fin.low, 'tall');
      });
    }

    // scal blaty sąsiednich szafek w jeden (bez szwów)
    var tops = boxes.filter(function (b) { return b.tag === 'top'; }), merged = true;
    var eq = function (a, b) { return Math.abs(a - b) < 0.05; };
    while (merged) {
      merged = false;
      for (var i1 = 0; i1 < tops.length && !merged; i1++) for (var i2 = 0; i2 < tops.length && !merged; i2++) {
        var a1 = tops[i1], b1 = tops[i2];
        if (a1 === b1 || a1.wall !== b1.wall || !eq(a1.min[1], b1.min[1]) || !eq(a1.max[1], b1.max[1])) continue;
        var alongX = eq(a1.min[2], b1.min[2]) && eq(a1.max[2], b1.max[2]) && eq(a1.max[0], b1.min[0]);
        var alongZ = eq(a1.min[0], b1.min[0]) && eq(a1.max[0], b1.max[0]) && eq(a1.max[2], b1.min[2]);
        if (alongX || alongZ) {
          a1.max = [Math.max(a1.max[0], b1.max[0]), a1.max[1], Math.max(a1.max[2], b1.max[2])];
          tops.splice(i2, 1); boxes.splice(boxes.indexOf(b1), 1); merged = true;
        }
      }
    }

    // podłoga
    var fx1 = Math.max(maxX, 200) + 8, fz1 = Math.max(maxZ, p.layout === 'II' ? +p.roomDepth : 0, 260) + 40;
    var floor = { min: [-8, -1.5, -8], max: [fx1, 0, fz1], c: COL.floor };

    return { boxes: boxes, walls: walls, floor: floor, dims: dims, warnings: warnings, summary: summary, H: H };
  }

  function rowName(type, row) { var r = TYPES[type].rows.filter(function (x) { return x.id === row; })[0]; return r ? r.name : row; }
  function mix(a, b, t) { return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t]; }

  /** Opis projektu do wyceny (tekst + tabela) */
  function describe(p) {
    var m = buildModel(p), T = TYPES[p.type];
    var lines = [];
    lines.push(T.name + ', układ: ' + (LAYOUTS[p.layout] || LAYOUTS.I).name + ', wysokość pomieszczenia: ' + p.height + ' cm');
    wallsOf(p).forEach(function (w) { lines.push('Ściana ' + w.key + ': ' + w.L + ' cm'); });
    if (p.type === 'kuchnia' && p.island.on) lines.push('Wyspa: ' + p.island.w + ' × ' + p.island.d + ' cm');
    var f = p.finish, fx = [];
    if (p.type === 'kuchnia') {
      fx.push('Fronty dolne: ' + finish('front', f.low).name, 'Fronty górne: ' + finish('front', f.up).name, 'Korpusy: ' + finish('body', f.body).name, 'Blat: ' + finish('top', f.top).name, 'Uchwyty: ' + finish('handle', f.handle).name, 'Szafki górne: ' + f.upH + ' cm', 'Oświetlenie LED pod szafkami: ' + (f.led ? 'tak' : 'nie'));
    } else if (p.type === 'szafa') {
      fx.push('Drzwi: ' + ({ przesuwne: 'przesuwne (' + f.doorCount + ' skrzydła)', uchylne: 'uchylne', brak: 'bez drzwi' }[f.doors] || f.doors), 'Fronty: ' + finish('front', f.low).name, 'Korpus: ' + finish('body', f.body).name, 'Głębokość: ' + f.depth + ' cm');
      if (f.doors === 'uchylne') fx.push('Uchwyty: ' + finish('handle', f.handle).name);
    } else if (p.type === 'garderoba') {
      fx.push('Korpus i półki: ' + finish('body', f.body).name, 'Fronty szuflad: ' + finish('front', f.low).name, 'Głębokość: ' + f.depth + ' cm');
    } else {
      fx.push('Fronty szafek: ' + finish('front', f.low).name, 'Fronty wiszących: ' + finish('front', f.up).name, 'Korpusy: ' + finish('body', f.body).name, 'Blat: ' + finish('top', f.top).name, 'Uchwyty: ' + finish('handle', f.handle).name, 'Podświetlenie lustra: ' + (f.led ? 'tak' : 'nie'));
    }
    return { title: T.name, lines: lines, finishes: fx, items: m.summary, warnings: m.warnings.map(function (w) { return w.text; }) };
  }

  /* ======================================================================
   * 4. Podgląd 3D (WebGL 1, bez bibliotek)
   * ==================================================================== */
  var VS = [
    'attribute vec3 aP; attribute vec3 aN; attribute vec3 aC; attribute float aM;',
    'uniform mat4 uVP; varying vec3 vP; varying vec3 vN; varying vec3 vC; varying float vM;',
    'void main(){ vP=aP; vN=aN; vC=aC; vM=aM; gl_Position=uVP*vec4(aP,1.0); }'
  ].join('\n');
  var FS = [
    'precision mediump float;',
    'varying vec3 vP; varying vec3 vN; varying vec3 vC; varying float vM;',
    'uniform vec3 uEye; uniform float uLine; uniform vec3 uLineC;',
    'float h(vec2 p){ return fract(sin(dot(p, vec2(12.9898,78.233)))*43758.5453); }',
    'void main(){',
    ' if(uLine>0.5){ gl_FragColor=vec4(uLineC,1.0); return; }',
    ' vec3 n=normalize(vN); vec3 c=vC;',
    ' if(vM>2.5 && vM<3.5){ gl_FragColor=vec4(c,1.0); return; }',
    ' if(vM>0.5 && vM<1.5){',  // drewno: słoje wzdłuż dłuższej osi ściany frontu
    '   vec2 q = abs(n.y)>0.5 ? vec2(vP.x+vP.z*0.3, vP.z-vP.x*0.3) : (abs(n.x)>0.5 ? vec2(vP.z, vP.y) : vec2(vP.x, vP.y));',
    '   float g = sin(q.x*0.9 + sin(q.y*0.045)*6.0 + sin(q.y*0.13+q.x*0.05)*1.6);',
    '   float f = smoothstep(0.7,1.0,abs(g));',
    '   c = c*(0.955+0.045*sin(q.x*0.21+q.y*0.012)) - vec3(0.028,0.022,0.016)*f;',
    ' }',
    ' vec3 L1=normalize(vec3(0.45,0.85,0.55)); vec3 L2=normalize(vec3(-0.6,0.35,0.7));',
    ' float d=max(dot(n,L1),0.0)*0.42 + max(dot(n,L2),0.0)*0.18;',
    ' float amb=0.52 + 0.12*n.y;',
    ' vec3 col=c*(amb+d);',
    ' vec3 V=normalize(uEye-vP);',
    ' if(vM>1.5 && vM<2.5){ vec3 R=reflect(-L1,n); col += vec3(0.32)*pow(max(dot(R,V),0.0),36.0); }',
    ' if(vM>3.5){ vec3 R=reflect(-V,n); float fr=pow(1.0-max(dot(n,V),0.0),3.0); col = c*0.9 + vec3(0.25)*fr + vec3(0.12)*(0.5+0.5*R.y); }',
    ' col *= 0.985+0.03*h(gl_FragCoord.xy);',
    ' gl_FragColor=vec4(col,1.0);',
    '}'
  ].join('\n');

  // --- macierze 4x4 (kolumnowe) ---
  function perspective(fovy, aspect, near, far) {
    var f = 1 / Math.tan(fovy / 2), nf = 1 / (near - far);
    return new Float32Array([f / aspect, 0, 0, 0, 0, f, 0, 0, 0, 0, (far + near) * nf, -1, 0, 0, 2 * far * near * nf, 0]);
  }
  function lookAt(e, t, up) {
    var z = norm(sub(e, t)), x = norm(cross(up, z)), y = cross(z, x);
    return new Float32Array([x[0], y[0], z[0], 0, x[1], y[1], z[1], 0, x[2], y[2], z[2], 0, -dot(x, e), -dot(y, e), -dot(z, e), 1]);
  }
  function mul(a, b) {
    var o = new Float32Array(16);
    for (var c = 0; c < 4; c++) for (var r = 0; r < 4; r++) {
      var s = 0; for (var k = 0; k < 4; k++) s += a[k * 4 + r] * b[c * 4 + k]; o[c * 4 + r] = s;
    }
    return o;
  }
  function sub(a, b) { return [a[0] - b[0], a[1] - b[1], a[2] - b[2]]; }
  function dot(a, b) { return a[0] * b[0] + a[1] * b[1] + a[2] * b[2]; }
  function cross(a, b) { return [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]]; }
  function norm(a) { var l = Math.hypot(a[0], a[1], a[2]) || 1; return [a[0] / l, a[1] / l, a[2] / l]; }

  var FACES = [ // normal, 4 narożniki jako indeksy (bit0=x,bit1=y,bit2=z)
    [[1, 0, 0], [1, 3, 7, 5]], [[-1, 0, 0], [0, 4, 6, 2]],
    [[0, 1, 0], [2, 6, 7, 3]], [[0, -1, 0], [0, 1, 5, 4]],
    [[0, 0, 1], [4, 5, 7, 6]], [[0, 0, -1], [0, 2, 3, 1]]
  ];
  var EDGES = [[0, 1], [2, 3], [4, 5], [6, 7], [0, 2], [1, 3], [4, 6], [5, 7], [0, 4], [1, 5], [2, 6], [3, 7]];

  function Mesh() { this.v = []; this.l = []; }
  Mesh.prototype.addBox = function (b, edges) {
    var mn = b.min, mx = b.max, c = b.c, m = b.m || 0, v = this.v;
    var P = function (i) { return [(i & 1) ? mx[0] : mn[0], (i & 2) ? mx[1] : mn[1], (i & 4) ? mx[2] : mn[2]]; };
    FACES.forEach(function (f) {
      var q = f[1].map(P), n = f[0];
      [0, 1, 2, 0, 2, 3].forEach(function (k) { var p = q[k]; v.push(p[0], p[1], p[2], n[0], n[1], n[2], c[0], c[1], c[2], m); });
    });
    if (edges) { var l = this.l; EDGES.forEach(function (e) { var a = P(e[0]), z = P(e[1]); l.push(a[0], a[1], a[2], z[0], z[1], z[2]); }); }
  };

  function Viewer(canvas, overlay) {
    this.canvas = canvas; this.overlay = overlay;
    var gl = canvas.getContext('webgl', { antialias: true, preserveDrawingBuffer: true, alpha: false }) || canvas.getContext('experimental-webgl', { preserveDrawingBuffer: true });
    this.gl = gl;
    if (!gl) { this.failed = true; return; }
    var prog = gl.createProgram();
    [[gl.VERTEX_SHADER, VS], [gl.FRAGMENT_SHADER, FS]].forEach(function (s) {
      var sh = gl.createShader(s[0]); gl.shaderSource(sh, s[1]); gl.compileShader(sh);
      if (!gl.getShaderParameter(sh, gl.COMPILE_STATUS)) throw new Error(gl.getShaderInfoLog(sh));
      gl.attachShader(prog, sh);
    });
    gl.linkProgram(prog); gl.useProgram(prog);
    this.prog = prog;
    this.loc = {
      aP: gl.getAttribLocation(prog, 'aP'), aN: gl.getAttribLocation(prog, 'aN'), aC: gl.getAttribLocation(prog, 'aC'), aM: gl.getAttribLocation(prog, 'aM'),
      uVP: gl.getUniformLocation(prog, 'uVP'), uEye: gl.getUniformLocation(prog, 'uEye'), uLine: gl.getUniformLocation(prog, 'uLine'), uLineC: gl.getUniformLocation(prog, 'uLineC')
    };
    this.groups = [];
    this.cam = { yaw: -0.42, pitch: 0.38, dist: 600, target: [150, 110, 80] };
    this.labels = [];
    this.onPick = null;
    this._bind();
    var self = this;
    this._ro = global.ResizeObserver ? new ResizeObserver(function () { self.render(); }) : null;
    if (this._ro) this._ro.observe(canvas); else global.addEventListener('resize', function () { self.render(); });
  }

  Viewer.prototype._upload = function (mesh) {
    var gl = this.gl, g = { tri: gl.createBuffer(), lin: gl.createBuffer(), nt: mesh.v.length / 10, nl: mesh.l.length / 3 };
    gl.bindBuffer(gl.ARRAY_BUFFER, g.tri); gl.bufferData(gl.ARRAY_BUFFER, new Float32Array(mesh.v), gl.STATIC_DRAW);
    gl.bindBuffer(gl.ARRAY_BUFFER, g.lin); gl.bufferData(gl.ARRAY_BUFFER, new Float32Array(mesh.l.length ? mesh.l : [0, 0, 0]), gl.STATIC_DRAW);
    return g;
  };

  Viewer.prototype.setModel = function (model, keepView) {
    if (this.failed) return;
    var gl = this.gl, self = this;
    this.groups.forEach(function (g) { gl.deleteBuffer(g.tri); gl.deleteBuffer(g.lin); });
    this.groups = []; this.model = model;
    var fm = new Mesh(); fm.addBox(model.floor, false);
    var main = new Mesh(); model.boxes.forEach(function (b) { main.addBox(b, b.e); });
    var dm = new Mesh(); model.dims.forEach(function (d) {
      dm.l.push(d.a[0], d.a[1], d.a[2], d.b[0], d.b[1], d.b[2]);
      [d.a, d.b].forEach(function (p) { dm.l.push(p[0], p[1] - 5, p[2], p[0], p[1] + 5, p[2]); });
    });
    this.groups.push(Object.assign(this._upload(fm), { kind: 'floor' }));
    model.walls.forEach(function (w) { var m = new Mesh(); m.addBox(w, true); self.groups.push(Object.assign(self._upload(m), { kind: 'wall', wall: w })); });
    this.groups.push(Object.assign(this._upload(main), { kind: 'main' }));
    this.groups.push(Object.assign(this._upload(dm), { kind: 'dims' }));
    // obwiednia
    var mn = [1e9, 1e9, 1e9], mx = [-1e9, -1e9, -1e9];
    model.boxes.concat(model.walls).forEach(function (b) { for (var i = 0; i < 3; i++) { mn[i] = Math.min(mn[i], b.min[i]); mx[i] = Math.max(mx[i], b.max[i]); } });
    if (mn[0] > mx[0]) { mn = [0, 0, 0]; mx = [300, 250, 200]; }
    this.bounds = { min: mn, max: mx };
    if (!keepView) this.resetView();
    this._labels(model);
    this.render();
  };

  Viewer.prototype._labels = function (model) {
    if (!this.overlay) return;
    this.overlay.innerHTML = '';
    var self = this;
    this.labels = model.dims.map(function (d) {
      var el = document.createElement('span');
      el.className = 'smk-dim'; el.textContent = d.text;
      self.overlay.appendChild(el);
      return { el: el, p: [(d.a[0] + d.b[0]) / 2, d.a[1] + 6, (d.a[2] + d.b[2]) / 2], wall: d.wall };
    });
  };

  Viewer.prototype.resetView = function (yaw, pitch) {
    var b = this.bounds || { min: [0, 0, 0], max: [300, 250, 200] };
    var c = [(b.min[0] + b.max[0]) / 2, Math.min(110, (b.min[1] + b.max[1]) / 2), (b.min[2] + b.max[2]) / 2 + 20];
    var r = Math.hypot(b.max[0] - b.min[0], b.max[1] - b.min[1], b.max[2] - b.min[2]) / 2;
    this.cam.target = c;
    this.cam.yaw = yaw != null ? yaw : -0.42;
    this.cam.pitch = pitch != null ? pitch : 0.36;
    var asp = Math.max(0.5, (this.canvas.clientWidth || 800) / (this.canvas.clientHeight || 600));
    this.cam.dist = r / Math.sin(0.42) * (asp < 1 ? 1.35 / asp : 1.05);
    this.render();
  };

  Viewer.prototype.eye = function () {
    var c = this.cam;
    return [c.target[0] + c.dist * Math.cos(c.pitch) * Math.sin(c.yaw), c.target[1] + c.dist * Math.sin(c.pitch), c.target[2] + c.dist * Math.cos(c.pitch) * Math.cos(c.yaw)];
  };

  Viewer.prototype.render = function () {
    if (this.failed || !this.model) return;
    var self = this;
    if (this._raf) return;
    this._raf = (global.requestAnimationFrame || setTimeout)(function () { self._raf = null; self.draw(); });
  };

  Viewer.prototype.draw = function () {
    var gl = this.gl, cv = this.canvas, L = this.loc;
    var dpr = Math.min(global.devicePixelRatio || 1, 2);
    var W = Math.max(1, Math.round(cv.clientWidth * dpr)), H = Math.max(1, Math.round(cv.clientHeight * dpr));
    if (cv.width !== W || cv.height !== H) { cv.width = W; cv.height = H; }
    gl.viewport(0, 0, W, H);
    gl.clearColor(0.957, 0.957, 0.945, 1); gl.clear(gl.COLOR_BUFFER_BIT | gl.DEPTH_BUFFER_BIT);
    gl.enable(gl.DEPTH_TEST); gl.depthFunc(gl.LEQUAL);
    var eye = this.eye();
    var proj = perspective(0.84, W / H, 5, 8000);
    var view = lookAt(eye, this.cam.target, [0, 1, 0]);
    var vp = mul(proj, view); this._vp = vp; this._eye = eye;
    gl.uniformMatrix4fv(L.uVP, false, vp); gl.uniform3fv(L.uEye, eye);
    var stride = 40;
    var visibleWalls = {};
    this.groups.forEach(function (g) {
      if (g.kind === 'wall') {
        var w = g.wall, toEye = sub(eye, w.center);
        if (toEye[0] * w.n[0] + toEye[2] * w.n[1] < 0) return; // kamera za ścianą – ukryj (widok "domek dla lalek")
        visibleWalls[w.key] = true;
      }
      if (g.nt) {
        gl.uniform1f(L.uLine, 0);
        gl.bindBuffer(gl.ARRAY_BUFFER, g.tri);
        gl.enableVertexAttribArray(L.aP); gl.vertexAttribPointer(L.aP, 3, gl.FLOAT, false, stride, 0);
        gl.enableVertexAttribArray(L.aN); gl.vertexAttribPointer(L.aN, 3, gl.FLOAT, false, stride, 12);
        gl.enableVertexAttribArray(L.aC); gl.vertexAttribPointer(L.aC, 3, gl.FLOAT, false, stride, 24);
        gl.enableVertexAttribArray(L.aM); gl.vertexAttribPointer(L.aM, 1, gl.FLOAT, false, stride, 36);
        gl.enable(gl.POLYGON_OFFSET_FILL); gl.polygonOffset(1, 1);
        gl.drawArrays(gl.TRIANGLES, 0, g.nt);
        gl.disable(gl.POLYGON_OFFSET_FILL);
      }
      if (g.nl) {
        gl.uniform1f(L.uLine, 1);
        gl.uniform3fv(L.uLineC, g.kind === 'dims' ? COL.dim : (g.kind === 'wall' ? [0.78, 0.77, 0.74] : [0.24, 0.25, 0.27]));
        gl.bindBuffer(gl.ARRAY_BUFFER, g.lin);
        gl.enableVertexAttribArray(L.aP); gl.vertexAttribPointer(L.aP, 3, gl.FLOAT, false, 12, 0);
        gl.disableVertexAttribArray(L.aN); gl.disableVertexAttribArray(L.aC); gl.disableVertexAttribArray(L.aM);
        gl.vertexAttrib3f(L.aN, 0, 1, 0); gl.vertexAttrib3f(L.aC, 0, 0, 0); gl.vertexAttrib1f(L.aM, 0);
        if (g.kind === 'dims') gl.disable(gl.DEPTH_TEST);
        gl.drawArrays(gl.LINES, 0, g.nl);
        gl.enable(gl.DEPTH_TEST);
      }
    });
    // etykiety wymiarów
    var cw = cv.clientWidth, ch = cv.clientHeight;
    this.labels.forEach(function (lb) {
      var p = lb.p, x = vp[0] * p[0] + vp[4] * p[1] + vp[8] * p[2] + vp[12], y = vp[1] * p[0] + vp[5] * p[1] + vp[9] * p[2] + vp[13], w = vp[3] * p[0] + vp[7] * p[1] + vp[11] * p[2] + vp[15];
      if (w <= 0) { lb.el.style.display = 'none'; return; }
      lb.el.style.display = '';
      lb.el.style.transform = 'translate(' + Math.round((x / w * 0.5 + 0.5) * cw) + 'px,' + Math.round((0.5 - y / w * 0.5) * ch) + 'px) translate(-50%,-100%)';
    });
  };

  /** Wskazanie modułu kliknięciem: promień z kamery vs prostopadłościany */
  Viewer.prototype.pick = function (cx, cy) {
    if (!this.model || !this._vp) return null;
    var r = this.canvas.getBoundingClientRect();
    var nx = ((cx - r.left) / r.width) * 2 - 1, ny = 1 - ((cy - r.top) / r.height) * 2;
    var eye = this._eye, c = this.cam;
    var fwd = norm(sub(c.target, eye)), right = norm(cross(fwd, [0, 1, 0])), up = cross(right, fwd);
    var t = Math.tan(0.42), asp = r.width / r.height;
    var dir = norm([fwd[0] + right[0] * nx * t * asp + up[0] * ny * t, fwd[1] + right[1] * nx * t * asp + up[1] * ny * t, fwd[2] + right[2] * nx * t * asp + up[2] * ny * t]);
    var best = null, bt = 1e9;
    this.model.boxes.forEach(function (b) {
      if (!b.uid) return;
      var tmin = -1e9, tmax = 1e9;
      for (var i = 0; i < 3; i++) {
        if (Math.abs(dir[i]) < 1e-9) { if (eye[i] < b.min[i] || eye[i] > b.max[i]) return; continue; }
        var t1 = (b.min[i] - eye[i]) / dir[i], t2 = (b.max[i] - eye[i]) / dir[i];
        tmin = Math.max(tmin, Math.min(t1, t2)); tmax = Math.min(tmax, Math.max(t1, t2));
      }
      if (tmax >= tmin && tmin > 0 && tmin < bt) { bt = tmin; best = b.uid; }
    });
    return best;
  };

  Viewer.prototype._bind = function () {
    var self = this, cv = this.canvas, drag = null, pinch = null, moved = 0;
    cv.style.touchAction = 'none';
    cv.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    cv.addEventListener('pointerdown', function (e) {
      cv.setPointerCapture(e.pointerId);
      drag = { x: e.clientX, y: e.clientY, pan: e.button === 2 || e.shiftKey, id: e.pointerId };
      moved = 0;
    });
    cv.addEventListener('pointermove', function (e) {
      if (!drag || drag.id !== e.pointerId || pinch) return;
      var dx = e.clientX - drag.x, dy = e.clientY - drag.y; drag.x = e.clientX; drag.y = e.clientY;
      moved += Math.abs(dx) + Math.abs(dy);
      if (drag.pan) {
        var eye = self.eye(), f = norm(sub(self.cam.target, eye)), r = norm(cross(f, [0, 1, 0])), u = cross(r, f), k = self.cam.dist / 900;
        for (var i = 0; i < 3; i++) self.cam.target[i] += (-r[i] * dx + u[i] * dy) * k;
      } else {
        self.cam.yaw -= dx * 0.008;
        self.cam.pitch = Math.max(0.06, Math.min(1.45, self.cam.pitch + dy * 0.006));
      }
      self.render();
    });
    cv.addEventListener('pointerup', function (e) {
      if (drag && moved < 5 && self.onPick) self.onPick(self.pick(e.clientX, e.clientY));
      drag = null;
    });
    cv.addEventListener('wheel', function (e) {
      e.preventDefault();
      self.cam.dist = Math.max(80, Math.min(4000, self.cam.dist * Math.exp(e.deltaY * 0.0012)));
      self.render();
    }, { passive: false });
    // dotyk: dwa palce = przybliżanie
    var touches = {};
    cv.addEventListener('pointerdown', function (e) { if (e.pointerType === 'touch') touches[e.pointerId] = [e.clientX, e.clientY]; });
    cv.addEventListener('pointermove', function (e) {
      if (e.pointerType !== 'touch' || !touches[e.pointerId]) return;
      touches[e.pointerId] = [e.clientX, e.clientY];
      var ids = Object.keys(touches);
      if (ids.length === 2) {
        var a = touches[ids[0]], b = touches[ids[1]], d = Math.hypot(a[0] - b[0], a[1] - b[1]);
        if (pinch) { self.cam.dist = Math.max(80, Math.min(4000, self.cam.dist * pinch / d)); self.render(); }
        pinch = d;
      }
    });
    var endTouch = function (e) { delete touches[e.pointerId]; if (Object.keys(touches).length < 2) pinch = null; };
    cv.addEventListener('pointerup', endTouch); cv.addEventListener('pointercancel', endTouch);
  };

  /** Zrzuty ekranu z kilku ujęć – do wyceny */
  Viewer.prototype.snapshots = function (w, h) {
    if (this.failed || !this.model) return [];
    var cv = this.canvas, saved = JSON.parse(JSON.stringify(this.cam)), out = [];
    var ow = cv.style.width, oh = cv.style.height, pos = cv.style.position;
    cv.style.width = (w || 1200) + 'px'; cv.style.height = (h || 800) + 'px'; cv.style.position = 'fixed';
    var self = this;
    [[-0.42, 0.36], [0.55, 0.3], [0, 1.2]].forEach(function (v) {
      self.resetView(v[0], v[1]); if (self._raf) { (global.cancelAnimationFrame || clearTimeout)(self._raf); self._raf = null; }
      self.draw();
      out.push(cv.toDataURL('image/jpeg', 0.86));
    });
    cv.style.width = ow; cv.style.height = oh; cv.style.position = pos;
    this.cam = saved; this.draw();
    return out;
  };

  global.SMK = {
    FINISHES: FINISHES, finish: finish, TYPES: TYPES, LAYOUTS: LAYOUTS, CATALOG: CATALOG, CAT: CAT,
    defaultProject: defaultProject, buildModel: buildModel, layoutRow: layoutRow, wallsOf: wallsOf, startOffset: startOffset,
    describe: describe, item: item, rowName: rowName, Viewer: Viewer, hex: hex
  };
})(typeof window !== 'undefined' ? window : this);
