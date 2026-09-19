/**
 * WC Gotify Sidebar Slider
 *
 * Auto-plays through banner slides with dot navigation.
 * Pauses on hover, resumes on mouse leave.
 *
 * Expects `wcGotifySlider` global with:
 *   - sliderId:      DOM id of the slider container
 *   - slideInterval:  milliseconds between auto-advances
 */
(function () {
	var config = window.wcGotifySlider;
	if (!config || !config.sliderId) {
		return;
	}

	var slider = document.getElementById(config.sliderId);
	if (!slider) {
		return;
	}

	var slides = slider.querySelectorAll('.wc-gotify-sb-slide');
	var dots = slider.querySelectorAll('.wc-gotify-sb-dot');

	if (slides.length < 2) {
		return;
	}

	var currentIndex = 0;
	var intervalTime = config.slideInterval || 4500;
	var timer = null;

	function showSlide(idx) {
		slides[currentIndex].style.display = 'none';
		dots[currentIndex].classList.remove('is-active');

		currentIndex = (idx + slides.length) % slides.length;

		slides[currentIndex].style.display = 'block';
		dots[currentIndex].classList.add('is-active');
	}

	function startAutoPlay() {
		stopAutoPlay();
		timer = setInterval(function () {
			showSlide(currentIndex + 1);
		}, intervalTime);
	}

	function stopAutoPlay() {
		if (timer) {
			clearInterval(timer);
			timer = null;
		}
	}

	dots.forEach(function (dot) {
		dot.addEventListener('click', function (e) {
			e.preventDefault();
			showSlide(parseInt(this.getAttribute('data-index'), 10));
			startAutoPlay();
		});
	});

	slider.addEventListener('mouseenter', stopAutoPlay);
	slider.addEventListener('mouseleave', startAutoPlay);

	startAutoPlay();
})();
