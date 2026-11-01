document.addEventListener('DOMContentLoaded', () => {
    const slider = document.querySelector('[data-hero-slider]');
    if (!slider) return;

    const slides = Array.from(slider.querySelectorAll('[data-slide]'));
    const dots = Array.from(slider.querySelectorAll('[data-slide-to]'));
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let current = 0;
    let timer;

    function show(index) {
        current = (index + slides.length) % slides.length;
        slides.forEach((slide, slideIndex) => {
            const active = slideIndex === current;
            slide.classList.toggle('is-active', active);
            slide.toggleAttribute('inert', !active);
            slide.setAttribute('aria-hidden', String(!active));
        });
        dots.forEach((dot, dotIndex) => {
            dot.classList.toggle('is-active', dotIndex === current);
            if (dotIndex === current) dot.setAttribute('aria-current', 'true');
            else dot.removeAttribute('aria-current');
        });
    }

    function stop() { window.clearInterval(timer); timer = undefined; }
    function start() {
        stop();
        if (!reduceMotion.matches && !document.hidden) timer = window.setInterval(() => show(current + 1), 7000);
    }

    dots.forEach((dot, index) => dot.addEventListener('click', () => { show(index); start(); }));
    slider.querySelector('[data-slide-prev]').addEventListener('click', () => { show(current - 1); start(); });
    slider.querySelector('[data-slide-next]').addEventListener('click', () => { show(current + 1); start(); });
    slider.addEventListener('mouseenter', stop);
    slider.addEventListener('mouseleave', start);
    slider.addEventListener('focusin', stop);
    slider.addEventListener('focusout', event => { if (!slider.contains(event.relatedTarget)) start(); });
    document.addEventListener('visibilitychange', start);
    reduceMotion.addEventListener('change', start);
    show(0);
    start();
});
