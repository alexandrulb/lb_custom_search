(function () {
	'use strict';
	if (!window.WCLS || !WCLS.ajaxUrl || !WCLS.nonce) return;

	function $(sel, root) { return (root || document).querySelector(sel); }
	function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

	function debounce(fn, wait) {
		let t;
		return function () {
			clearTimeout(t);
			const args = arguments, ctx = this;
			t = setTimeout(function () { fn.apply(ctx, args); }, wait);
		};
	}

	function escapeHtml(s) {
		return s.replace(/[&<>"']/g, function (c) {
			return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
		});
	}

	function setLoading(wrap, on) {
		wrap.classList.toggle('wcls-loading', !!on);
	}

	function hideResults(wrap) {
		const res = $('.wcls-results', wrap);
		res.classList.remove('wcls-show');
		res.setAttribute('aria-expanded', 'false');
		// leave structure intact; just clear lists and message
		$all('.wcls-section-list', res).forEach(function (el) { el.innerHTML = ''; });
		const msg = $('.wcls-empty-all', res);
		if (msg) msg.style.display = 'none';
	}

	function switchTab(wrap, tab) {
		const tabs = $all('.wcls-tab', wrap);
		const panels = $all('.wcls-panel', wrap);
		tabs.forEach(function (t) {
			const active = t.dataset.tab === tab;
			t.classList.toggle('is-active', active);
			t.setAttribute('aria-selected', active ? 'true' : 'false');
		});
		panels.forEach(function (p) {
			p.classList.toggle('is-active', p.dataset.panel === tab);
		});
	}

	function renderTermItems(target, items) {
		if (!target) return 0;
		if (!items || !items.length) {
			target.parentElement.classList.add('wcls-hidden');
			target.innerHTML = '';
			return 0;
		}
		target.parentElement.classList.remove('wcls-hidden');
		const html = items.map(function (it) {
			return `
				<a class="wcls-row wcls-term" role="option" href="${it.url}">
					<span class="wcls-title">${escapeHtml(it.name)}</span>
					<span class="wcls-badge">${it.count || ''}</span>
				</a>
			`;
		}).join('');
		target.innerHTML = html;
		return items.length;
	}

	function renderProductItems(target, items, opts) {
		if (!target) return 0;
		if (!items || !items.length) {
			target.parentElement.classList.add('wcls-hidden');
			target.innerHTML = '';
			return 0;
		}
		target.parentElement.classList.remove('wcls-hidden');
		const html = items.map(function (it) {
			const img = opts.showImage && it.thumbnail ? `<img class="wcls-thumb" src="${it.thumbnail}" alt="">` : '';
			const price = opts.showPrice && it.price_html ? `<div class="wcls-price">${it.price_html}</div>` : '';
			return `
				<a class="wcls-row wcls-product" role="option" href="${it.url}">
					${img}
					<div class="wcls-title">${escapeHtml(it.title)}</div>
					${price}
				</a>
			`;
		}).join('');
		target.innerHTML = html;
		return items.length;
	}

	function renderAll(wrap, payload, opts) {
		const results = $('.wcls-results', wrap);
		const watches = payload && payload.watches ? payload.watches : { collections:[], brands:[], references:[], products:[] };
		const total =
			renderTermItems($('[data-target="collections"]', results), watches.collections) +
			renderTermItems($('[data-target="brands"]', results), watches.brands) +
			renderTermItems($('[data-target="references"]', results), watches.references) +
			renderProductItems($('[data-target="products"]', results), watches.products, opts);

		const emptyMsg = $('.wcls-empty-all', results);
		if (emptyMsg) emptyMsg.style.display = total ? 'none' : 'block';

		results.classList.add('wcls-show');
		results.setAttribute('aria-expanded', 'true');
	}

	function initBox(wrap) {
		const input = $('.wcls-input', wrap);
		const results = $('.wcls-results', wrap);

		const opts = {
			minChars: parseInt(wrap.dataset.minChars || '2', 10),
			termLimit: parseInt(wrap.dataset.termLimit || '6', 10),
			productLimit: parseInt(wrap.dataset.productLimit || '8', 10),
			watchCat: wrap.dataset.watchCat || 'watches',
			collectionsAttr: wrap.dataset.collectionsAttr || 'brand_collection',
			brandsAttr: wrap.dataset.brandsAttr || 'lux_g_brand',
			refsAttr: wrap.dataset.refsAttr || 'lux_g_referencenumber',
			showPrice: wrap.dataset.showPrice === '1',
			showImage: wrap.dataset.showImage === '1',
		};

		// tab clicks
		results.addEventListener('click', function (e) {
			const btn = e.target.closest('.wcls-tab');
			if (!btn) return;
			switchTab(wrap, btn.dataset.tab);
		});

		const doSearch = debounce(function (term) {
			term = term.trim();
			if (term.length < opts.minChars) {
				hideResults(wrap);
				return;
			}
			setLoading(wrap, true);

			const body = new URLSearchParams();
			body.set('action', 'wcls_search');
			body.set('nonce', WCLS.nonce);
			body.set('q', term);
			body.set('term_limit', String(opts.termLimit));
			body.set('product_limit', String(opts.productLimit));
			body.set('watch_cat', opts.watchCat);
			body.set('collections_attr', opts.collectionsAttr);
			body.set('brands_attr', opts.brandsAttr);
			body.set('refs_attr', opts.refsAttr);

			fetch(WCLS.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
				body
			})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data && data.success) {
					renderAll(wrap, data.data, { showPrice: opts.showPrice, showImage: opts.showImage });
					switchTab(wrap, 'watches'); // keep focus on Watches for now
				} else {
					$('.wcls-empty-all', results).textContent = 'Something went wrong';
					results.classList.add('wcls-show');
				}
			})
			.catch(function () {
				$('.wcls-empty-all', results).textContent = 'Network error';
				results.classList.add('wcls-show');
			})
			.finally(function () {
				setLoading(wrap, false);
			});
		}, 250);

		input.addEventListener('input', function () { doSearch(this.value || ''); });
		input.addEventListener('keydown', function (e) { if (e.key === 'Escape') hideResults(wrap); });

		// Click outside to close
		document.addEventListener('click', function (e) {
			if (!wrap.contains(e.target)) hideResults(wrap);
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		$all('.wcls-wrap').forEach(initBox);
	});
})();
