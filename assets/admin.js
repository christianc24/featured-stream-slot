(() => {
	'use strict';
	const picker = document.getElementById('ssp-border-color');
	if (!picker) return;
	document.querySelectorAll('.ssp-swatch').forEach((button) => {
		button.addEventListener('click', () => {
			picker.value = button.dataset.color;
			picker.dispatchEvent(new Event('input', { bubbles: true }));
		});
	});
})();

