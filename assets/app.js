function initializeCarousel(carousel) {
    const slides = Array.from(carousel.querySelectorAll('[data-gallery-slide]'));
    if (slides.length < 2) {
        return;
    }

    const indicators = Array.from(carousel.querySelectorAll('[data-gallery-to]'));
    const position = carousel.querySelector('[data-gallery-position]');
    let activeIndex = slides.findIndex((slide) => !slide.hidden);
    if (activeIndex < 0) {
        activeIndex = 0;
    }

    const showSlide = (index) => {
        activeIndex = (index + slides.length) % slides.length;
        slides.forEach((slide, slideIndex) => {
            const active = slideIndex === activeIndex;
            slide.hidden = !active;
            slide.setAttribute('aria-hidden', String(!active));
        });
        indicators.forEach((indicator, indicatorIndex) => {
            if (indicatorIndex === activeIndex) {
                indicator.setAttribute('aria-current', 'true');
            } else {
                indicator.removeAttribute('aria-current');
            }
        });
        if (position) {
            position.textContent = `${activeIndex + 1} / ${slides.length}`;
        }
    };

    carousel.querySelector('[data-gallery-previous]')?.addEventListener('click', () => {
        showSlide(activeIndex - 1);
    });
    carousel.querySelector('[data-gallery-next]')?.addEventListener('click', () => {
        showSlide(activeIndex + 1);
    });
    indicators.forEach((indicator) => {
        indicator.addEventListener('click', () => {
            showSlide(Number(indicator.dataset.galleryTo));
        });
    });
    showSlide(activeIndex);
}

document.querySelectorAll('[data-carousel]').forEach(initializeCarousel);

const editor = document.querySelector('[data-question-editor]');
if (editor) {
    const prompt = editor.querySelector('[data-question-prompt]');
    const date = editor.querySelector('[data-question-date]');
    const imageInput = editor.querySelector('[data-question-images]');
    const preview = editor.querySelector('[data-question-preview]');
    const previewPrompt = editor.querySelector('[data-preview-prompt]');
    const previewDate = editor.querySelector('[data-preview-date]');
    const previewGallery = editor.querySelector('[data-preview-gallery]');
    const existingImages = Array.from(editor.querySelectorAll('[data-existing-image]'));
    let previewObjectUrls = [];

    const formattedDate = (value) => {
        if (!value) {
            return 'Your quiz date will appear here.';
        }
        const parsedDate = new Date(`${value}T12:00:00`);
        return new Intl.DateTimeFormat(undefined, {
            weekday: 'long',
            month: 'long',
            day: 'numeric',
        }).format(parsedDate);
    };

    const createPreviewGallery = (images) => {
        previewGallery.replaceChildren();

        if (images.length === 0) {
            return;
        }

        previewGallery.setAttribute('role', 'region');
        previewGallery.setAttribute('aria-label', 'Question image preview');
        previewGallery.setAttribute('aria-roledescription', 'carousel');

        const slidesContainer = document.createElement('div');
        slidesContainer.className = 'gallery-slides';

        images.forEach((image, index) => {
            const slide = document.createElement('div');
            slide.className = 'gallery-slide';
            slide.dataset.gallerySlide = '';
            slide.setAttribute('role', 'group');
            slide.setAttribute('aria-roledescription', 'slide');
            slide.setAttribute('aria-label', `${index + 1} of ${images.length}`);
            slide.hidden = index !== 0;

            const img = document.createElement('img');
            img.className = 'question-image';
            img.src = image.src;
            img.alt = `Question image ${index + 1} of ${images.length}`;
            slide.append(img);
            slidesContainer.append(slide);
        });
        previewGallery.append(slidesContainer);

        if (images.length > 1) {
            const controls = document.createElement('div');
            controls.className = 'gallery-controls';

            const previous = document.createElement('button');
            previous.className = 'gallery-arrow';
            previous.type = 'button';
            previous.dataset.galleryPrevious = '';
            previous.setAttribute('aria-label', 'Previous image');
            previous.textContent = '‹';

            const indicators = document.createElement('div');
            indicators.className = 'gallery-indicators';
            indicators.setAttribute('aria-label', 'Choose an image');
            images.forEach((_, index) => {
                const indicator = document.createElement('button');
                indicator.className = 'gallery-indicator';
                indicator.type = 'button';
                indicator.dataset.galleryTo = String(index);
                indicator.setAttribute('aria-label', `Show image ${index + 1} of ${images.length}`);
                if (index === 0) {
                    indicator.setAttribute('aria-current', 'true');
                }
                indicators.append(indicator);
            });

            const position = document.createElement('span');
            position.className = 'gallery-position';
            position.dataset.galleryPosition = '';
            position.setAttribute('aria-live', 'polite');
            position.textContent = `1 / ${images.length}`;

            const next = document.createElement('button');
            next.className = 'gallery-arrow';
            next.type = 'button';
            next.dataset.galleryNext = '';
            next.setAttribute('aria-label', 'Next image');
            next.textContent = '›';

            controls.append(previous, indicators, position, next);
            previewGallery.append(controls);
        }

        initializeCarousel(previewGallery);
    };

    const refreshPreview = () => {
        previewObjectUrls.forEach((url) => URL.revokeObjectURL(url));
        previewObjectUrls = [];

        if (previewPrompt) {
            previewPrompt.textContent = prompt?.value.trim() || 'Your question will appear here.';
        }
        if (previewDate) {
            previewDate.textContent = formattedDate(date?.value || '');
        }

        const images = existingImages
            .filter((item) => !item.querySelector('[data-remove-image]')?.checked)
            .map((item) => ({
                src: item.dataset.imageUrl,
            }));

        Array.from(imageInput?.files || []).forEach((file) => {
            const url = URL.createObjectURL(file);
            previewObjectUrls.push(url);
            images.push({ src: url });
        });
        createPreviewGallery(images);
    };

    prompt?.addEventListener('input', refreshPreview);
    date?.addEventListener('input', refreshPreview);
    imageInput?.addEventListener('change', refreshPreview);
    editor.querySelectorAll('[data-remove-image]').forEach((checkbox) => {
        checkbox.addEventListener('change', refreshPreview);
    });
    preview?.addEventListener('toggle', () => {
        if (preview.open) {
            refreshPreview();
        }
    });
    refreshPreview();
}
