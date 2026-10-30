/* Digital Atlas Clinic Suite — Phreesia self-scheduling. No jQuery; supports several widgets per page. */
(function () {
	'use strict';

	var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_content', 'utm_campaign', 'utm_term'];

	function utmString() {
		var out = '';
		try {
			UTM_KEYS.forEach(function (k) {
				var v = window.sessionStorage.getItem(k);
				if (v) out += '&' + k + '=' + encodeURIComponent(v);
			});
		} catch (e) { /* storage blocked */ }
		return out;
	}

	function hideLoader(frame) {
		var box = frame.closest('.dacs-scheduler');
		var loader = box && box.querySelector('.dacs-scheduler-loading');
		if (loader) loader.style.display = 'none';
	}

	function init() {
		var frames = document.querySelectorAll('.dacs-scheduler-frame');
		if (!frames.length) return;
		var utms = utmString();

		Array.prototype.forEach.call(frames, function (frame) {
			var src = frame.getAttribute('src') || '';
			if (utms && src.indexOf('utm_') === -1) frame.setAttribute('src', src + utms);
			frame.addEventListener('load', function () { hideLoader(frame); });
			setTimeout(function () { hideLoader(frame); }, 8000); // never leave the spinner up
		});

		window.addEventListener('message', function (event) {
			var data = event && event.data;
			if (!data || !data.eventName) return;

			var frame = null;
			Array.prototype.some.call(frames, function (f) {
				if (f.contentWindow === event.source) { frame = f; return true; }
				return false;
			});
			if (!frame) return; // message from something other than a Phreesia widget

			var name = data.eventName, payload = data.payload || {};

			if (name !== 'self-scheduling:resize') {
				window.dataLayer = window.dataLayer || [];
				window.dataLayer.push({
					event: 'self-scheduling',
					event_name: name,
					location_name: typeof window.location_name !== 'undefined' ? window.location_name : ''
				});
			}

			if (name === 'self-scheduling:resize' && payload.height) {
				frame.style.height = (payload.height + 100) + 'px';
				hideLoader(frame);
				// Legacy layouts: push #services-offered below an overlaid #appointment-form (no-op elsewhere).
				setTimeout(function () {
					var appt = document.querySelector('#appointment-form .appointment');
					var services = document.getElementById('services-offered');
					if (appt && services) {
						var cs = window.getComputedStyle(appt);
						services.style.paddingTop = (appt.offsetHeight + parseFloat(cs.marginTop) + parseFloat(cs.marginBottom)) + 'px';
					}
				}, 500);
			}

			if (name === 'self-scheduling:patient-info-entered') {
				var top = frame.getBoundingClientRect().top + window.pageYOffset - 200;
				window.scrollTo({ top: top, behavior: 'smooth' });
			}
		});
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
