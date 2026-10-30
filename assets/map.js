/* Digital Atlas Clinic Suite — HIPAA Map (Freshpaint / MapLibre). Builds every [freshpaint_map] on the page. */
(function () {
	'use strict';

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text) n.textContent = text; // text only: location data is never parsed as HTML
		return n;
	}

	function popupNode(m) {
		var box = el('div', 'dacs-map-popup');
		if (m.name) box.appendChild(el('h3', '', m.name));
		if (m.address) box.appendChild(el('address', '', m.address));
		if (m.phone) {
			var tel = el('a', 'dacs-map-phone', m.phone);
			tel.href = 'tel:' + m.phone.replace(/[^0-9+]/g, '');
			var row = el('div'); row.appendChild(tel); box.appendChild(row);
		}
		if (m.hours) box.appendChild(el('div', 'dacs-map-hours', m.hours));
		if (m.link && /^https?:\/\//i.test(m.link)) {
			var a = el('a', 'dacs-map-book', m.linkText || 'Book Appointment');
			a.href = m.link;
			box.appendChild(a);
		}
		return box;
	}

	function cardNode(m) {
		var card = el('button', 'dacs-map-card');
		card.type = 'button';
		card.appendChild(el('strong', '', m.name));
		if (m.address) card.appendChild(el('span', 'dacs-map-card-address', m.address));
		if (m.phone) card.appendChild(el('span', 'dacs-map-card-phone', m.phone));
		return card;
	}

	function findFocus(markers, focus) {
		if (!focus) return -1;
		var n = parseInt(focus, 10);
		if (!isNaN(n) && String(n) === String(focus).trim() && n >= 1 && n <= markers.length) return n - 1;
		var needle = String(focus).toLowerCase();
		for (var i = 0; i < markers.length; i++) {
			if (String(markers[i].name || '').toLowerCase().indexOf(needle) !== -1) return i;
		}
		return -1;
	}

	function build(root) {
		var cfg;
		try { cfg = JSON.parse(root.getAttribute('data-dacs-map')); } catch (e) { return; }
		var markers = (cfg.markers || []).filter(function (m) {
			return m.lngLat && isFinite(m.lngLat[0]) && isFinite(m.lngLat[1]);
		});
		if (!markers.length || typeof maplibregl === 'undefined') return;

		var canvas = root.querySelector('.dacs-map-canvas');
		var cards = cfg.showCards ? root.querySelector('.dacs-map-cards') : null;

		var map = new maplibregl.Map({
			container: canvas,
			style: 'https://freshpaint-hipaa-maps.com/' + cfg.envId + '/' + cfg.style + '/style-descriptor',
			center: markers[0].lngLat,
			zoom: cfg.zoom
		});
		map.addControl(new maplibregl.NavigationControl(), 'top-right');
		map.on('error', function (e) { if (window.console) console.warn('[Clinic Suite map]', e && e.error ? e.error.message || e.error : e); });
		root.dacsMap = map;

		markers.forEach(function (m) {
			var popup = new maplibregl.Popup({ offset: 25 }).setDOMContent(popupNode(m));
			new maplibregl.Marker({ color: cfg.color }).setLngLat(m.lngLat).setPopup(popup).addTo(map);
			if (cards) {
				var card = cardNode(m);
				card.addEventListener('click', function () {
					// Offset the marker downward so the popup opens above it with room to show.
					map.flyTo({ center: m.lngLat, zoom: cfg.zoomOnClick, offset: [0, Math.round(canvas.clientHeight * 0.25)] });
					popup.setLngLat(m.lngLat).addTo(map);
				});
				cards.appendChild(card);
			}
		});

		// Initial view: focused location, else fit all, else the single location.
		var f = findFocus(markers, cfg.focus);
		if (f >= 0) {
			map.jumpTo({ center: markers[f].lngLat, zoom: cfg.zoomOnClick });
		} else if (markers.length >= 2) {
			var bounds = new maplibregl.LngLatBounds(markers[0].lngLat, markers[0].lngLat);
			markers.forEach(function (m) { bounds.extend(m.lngLat); });
			map.fitBounds(bounds, { padding: 40, maxZoom: Math.min(cfg.zoom, 14), linear: true });
		} else {
			map.jumpTo({ center: markers[0].lngLat, zoom: cfg.zoom });
		}
	}

	function init() {
		var maps = document.querySelectorAll('[data-dacs-map]');
		for (var i = 0; i < maps.length; i++) build(maps[i]);
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
