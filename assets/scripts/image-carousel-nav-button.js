class ImageCarouselNavButton extends HTMLElement {
  constructor() {
    super();
  }

  connectedCallback() {
    if (this.dataset.variant === 'prev') {
      this.innerHTML = '<i class="material-icons">arrow_back_ios</i>';
    }
    else if (this.dataset.variant === 'next') {
      this.innerHTML = '<i class="material-icons">arrow_forward_ios</i>';
    }

    this.onclick = this.handleClick;
  }

  disconnectedCallback() {
    this.onclick = undefined;
  }

  handleClick() {
    const parent = this.parentElement;

    if (!parent) {
      throw new Error('Parent image-carousel element not found!');
    }

    const index = Number(parent.dataset.index);

    if (this.dataset.variant === 'prev') {
      moveSlides(index - 1, parent);
    }
    else if (this.dataset.variant === 'next') {
      moveSlides(index + 1, parent);
    }
  }
}

customElements.define('image-carousel-nav-button', ImageCarouselNavButton);

export function moveSlides(index, imageCarousel) {
  if (!imageCarousel) {
    throw new Error('image-carousel element not found!');
  }

  const images = imageCarousel.querySelector('.carousel-images');
  const imageWidth = images.firstElementChild.clientWidth;
  const maxIndex = images.children.length - 1;

  if (index < 0) {
    index = maxIndex;
  }
  else if (index > maxIndex) {
    index = 0;
  }

  imageCarousel.dataset.index = Number(index);

  const scroller = imageCarousel.querySelector('.carousel-images-scroller');
  scroller.scrollTo({ left: index * imageWidth, behavior: 'smooth' });

  const event = new CustomEvent('slideChange', {
    detail: { index },
    bubbles: true,
    cancelable: true
  });
  imageCarousel.dispatchEvent(event);
}
