(function () {
	'use strict';

	if (!window.elsWcPlaces || !Array.isArray(window.elsWcPlaces.places)) {
		return;
	}

	var transliteration = {
		'а':'a','б':'b','в':'v','г':'g','д':'d','ѓ':'gj','е':'e','ж':'zh','з':'z','ѕ':'dz','и':'i','ј':'j','к':'k','л':'l','љ':'lj','м':'m','н':'n','њ':'nj','о':'o','п':'p','р':'r','с':'s','т':'t','ќ':'kj','у':'u','ф':'f','х':'h','ц':'c','ч':'ch','џ':'dzh','ш':'sh',
		'č':'c','ć':'c','š':'s','ž':'z','đ':'dj'
	};

	function normalize(value) {
		return String(value || '').toLowerCase().split('').map(function (character) {
			return transliteration[character] || character;
		}).join('').replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
	}

	function loose(value) {
		return normalize(value).replace(/dzh/g, 'dz').replace(/zh/g, 'z').replace(/sh/g, 's').replace(/ch/g, 'c');
	}

	function sortedTokens(value) {
		return loose(value).split(' ').filter(Boolean).sort().join(' ');
	}

	var aliases = Object.create(null);
	var records = window.elsWcPlaces.places.map(function (place) {
		var primary = String(place).split(/[,(]/)[0];
		return {
			text: place,
			search: loose(place),
			primary: loose(primary)
		};
	});

	function addAlias(alias, place) {
		alias = loose(alias);
		if (!alias) return;
		if (!aliases[alias]) aliases[alias] = [];
		if (aliases[alias].indexOf(place) === -1) aliases[alias].push(place);
	}

	records.forEach(function (record) {
		addAlias(record.text, record.text);
		addAlias(record.primary, record.text);
		addAlias(sortedTokens(record.text), record.text);
	});

	function resolve(value) {
		var direct = aliases[loose(value)] || [];
		if (direct.length === 1) return direct[0];
		var sorted = aliases[sortedTokens(value)] || [];
		return sorted.length === 1 ? sorted[0] : null;
	}

	function search(value) {
		var query = loose(value);
		if (query.length < 2) return [];
		var words = query.split(' ').filter(Boolean);
		return records.filter(function (record) {
			return words.every(function (word) { return record.search.indexOf(word) !== -1; });
		}).map(function (record) {
			var score = 4;
			if (record.primary === query) score = 0;
			else if (record.search === query) score = 1;
			else if (record.primary.indexOf(query) === 0) score = 2;
			else if (record.search.indexOf(query) === 0) score = 3;
			return { text: record.text, score: score };
		}).sort(function (a, b) {
			return a.score - b.score || a.text.localeCompare(b.text, 'mk');
		}).slice(0, 20);
	}

	function addressType(input) {
		var signature = ((input.name || '') + ' ' + (input.id || '')).toLowerCase();
		if (signature.indexOf('shipping') !== -1) return 'shipping';
		if (signature.indexOf('billing') !== -1) return 'billing';
		var container = input.closest('[class*="shipping"], [class*="billing"]');
		if (container && String(container.className).toLowerCase().indexOf('shipping') !== -1) return 'shipping';
		return 'billing';
	}

	function countryFor(input) {
		var type = addressType(input);
		var selectors = ['#' + type + '-country', '#' + type + '_country', '[name="' + type + '_country"]'];
		for (var index = 0; index < selectors.length; index++) {
			var field = document.querySelector(selectors[index]);
			if (field && field.value) return String(field.value).toUpperCase();
		}
		return String(window.elsWcPlaces.defaultCountry || '').toUpperCase();
	}

	function isAllowed(input) {
		return countryFor(input) === String(window.elsWcPlaces.allowedCountry || 'MK').toUpperCase();
	}

	function setNativeValue(input, value) {
		if (input.value === value) return;
		var descriptor = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
		if (descriptor && descriptor.set) descriptor.set.call(input, value);
		else input.value = value;
		input.dispatchEvent(new Event('input', { bubbles: true }));
		input.dispatchEvent(new Event('change', { bubbles: true }));
	}

	var popup = document.createElement('div');
	popup.className = 'els-wc-place-suggestions';
	popup.setAttribute('role', 'listbox');
	popup.hidden = true;
	var activeInput = null;
	var activeResults = [];
	var activeIndex = -1;

	function positionPopup() {
		if (!activeInput || popup.hidden) return;
		var rectangle = activeInput.getBoundingClientRect();
		popup.style.left = Math.round(rectangle.left) + 'px';
		popup.style.top = Math.round(rectangle.bottom + 4) + 'px';
		popup.style.width = Math.round(rectangle.width) + 'px';
	}

	function closePopup() {
		popup.hidden = true;
		popup.replaceChildren();
		activeInput = null;
		activeResults = [];
		activeIndex = -1;
	}

	function selectResult(index) {
		if (!activeInput || !activeResults[index]) return;
		var input = activeInput;
		setNativeValue(input, activeResults[index].text);
		closePopup();
	}

	function updateActiveOption() {
		popup.querySelectorAll('[role="option"]').forEach(function (option, index) {
			var selected = index === activeIndex;
			option.classList.toggle('is-active', selected);
			option.setAttribute('aria-selected', selected ? 'true' : 'false');
			if (selected) option.scrollIntoView({ block: 'nearest' });
		});
	}

	function renderPopup(input) {
		if (!isAllowed(input)) {
			closePopup();
			return;
		}
		var results = search(input.value);
		if (!results.length) {
			closePopup();
			return;
		}
		activeInput = input;
		activeResults = results;
		activeIndex = -1;
		popup.replaceChildren();
		results.forEach(function (result, index) {
			var option = document.createElement('button');
			option.type = 'button';
			option.className = 'els-wc-place-option';
			option.setAttribute('role', 'option');
			option.setAttribute('aria-selected', 'false');
			option.textContent = result.text;
			option.addEventListener('mousedown', function (event) {
				event.preventDefault();
				selectResult(index);
			});
			popup.appendChild(option);
		});
		popup.hidden = false;
		positionPopup();
	}

	function bindInputs() {
		var selector = ['input[name="billing_city"]', 'input[name="shipping_city"]', '#billing-city', '#shipping-city', 'input[autocomplete="address-level2"]'].join(',');
		document.querySelectorAll(selector).forEach(function (input) {
			if (!Object.prototype.hasOwnProperty.call(input.dataset, 'elsOriginalPlaceholder')) {
				input.dataset.elsOriginalPlaceholder = input.getAttribute('placeholder') || '';
			}
			if (!Object.prototype.hasOwnProperty.call(input.dataset, 'elsOriginalAutocomplete')) {
				input.dataset.elsOriginalAutocomplete = input.getAttribute('autocomplete') || '';
			}
			if (isAllowed(input)) {
				input.setAttribute('placeholder', window.elsWcPlaces.placeholder || '');
				input.setAttribute('autocomplete', 'off');
			} else {
				input.setAttribute('placeholder', input.dataset.elsOriginalPlaceholder || '');
				if (input.dataset.elsOriginalAutocomplete) input.setAttribute('autocomplete', input.dataset.elsOriginalAutocomplete);
				else input.removeAttribute('autocomplete');
			}
			if (input.dataset.elsPlacesBound) return;
			input.dataset.elsPlacesBound = '1';
			input.addEventListener('input', function () { renderPopup(input); });
			input.addEventListener('focus', function () { renderPopup(input); });
			input.addEventListener('blur', function () {
				window.setTimeout(function () {
					if (activeInput !== input) return;
					var place = isAllowed(input) ? resolve(input.value) : null;
					if (place) setNativeValue(input, place);
					closePopup();
				}, 120);
			});
			input.addEventListener('keydown', function (event) {
				if (popup.hidden || activeInput !== input) return;
				if (event.key === 'ArrowDown') {
					event.preventDefault();
					activeIndex = Math.min(activeIndex + 1, activeResults.length - 1);
					updateActiveOption();
				} else if (event.key === 'ArrowUp') {
					event.preventDefault();
					activeIndex = Math.max(activeIndex - 1, 0);
					updateActiveOption();
				} else if (event.key === 'Enter' && activeIndex >= 0) {
					event.preventDefault();
					selectResult(activeIndex);
				} else if (event.key === 'Escape') {
					closePopup();
				}
			});
		});
	}

	function start() {
		document.body.appendChild(popup);
		bindInputs();
		new MutationObserver(bindInputs).observe(document.documentElement, { childList: true, subtree: true });
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
	else start();

	document.addEventListener('change', function (event) {
		var target = event.target;
		if (target && /(?:billing|shipping)[_-]country/.test((target.name || '') + ' ' + (target.id || ''))) {
			bindInputs();
			if (activeInput && !isAllowed(activeInput)) closePopup();
		}
	});
	document.addEventListener('mousedown', function (event) {
		if (!popup.contains(event.target) && event.target !== activeInput) closePopup();
	});
	window.addEventListener('resize', positionPopup);
	window.addEventListener('scroll', positionPopup, true);
}());
