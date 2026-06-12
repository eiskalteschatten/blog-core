class ImageCarouselButton extends HTMLElement {
  constructor() {
    super();
  }

  connectedCallback() {
    this.innerHTML = '<i class="material-icons">open_in_full</i>';
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
    const figure = parent.querySelectorAll('figure')[index];
    const caption = figure.querySelector('figcaption')?.textContent;
    const imageSrc = figure.querySelector('img').src;
    openLightbox(imageSrc, caption);
  }
}

customElements.define('image-carousel-lightbox-button', ImageCarouselButton);
