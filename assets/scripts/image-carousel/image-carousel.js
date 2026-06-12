import { moveSlides } from './image-carousel-nav-button.js';

class ImageCarousel extends HTMLElement {
  isDragging = false;
  startX = 0;
  scrollLeft = 0;
  firstImageWidth = 0;

  constructor() {
    super();
  }

  connectedCallback() {
    this.dataset.index = 0;
    this.figures = this.querySelectorAll('figure');

    if (!this.figures?.length) {
      throw new Error('No figures could be found in the image carousel!');
    }

    // Wait for the first image to load before resizing
    const firstImage = this.figures[0].querySelectorAll('img')[0];
    if (firstImage.complete) {
      this.resizeImages();
      this.updateHtml();
    }
    else {
      firstImage.addEventListener('load', () => {
        this.resizeImages();
        this.updateHtml();
      });
    }

    window.addEventListener('resize', this.resizeImages.bind(this));
    this.addEventListener('slideChange', this.updateDots.bind(this));
    this.addEventListener('mousedown', this.handleMouseDown.bind(this));
    this.addEventListener('mouseup', this.handleMouseUpLeave.bind(this));
    this.addEventListener('mouseleave', this.handleMouseUpLeave.bind(this));
    this.addEventListener('mousemove', this.handleMouseMove.bind(this));
    this.addEventListener('touchstart', this.handleTouchStart.bind(this));
    this.addEventListener('touchmove', this.handleTouchMove.bind(this));
    this.addEventListener('touchend', this.handleTouchEnd.bind(this));
  }

  disconnectedCallback() {
    window.removeEventListener('resize', this.resizeImages.bind(this));
    this.removeEventListener('slideChange', this.updateDots.bind(this));
    this.removeEventListener('mousedown', this.handleMouseDown.bind(this));
    this.removeEventListener('mouseup', this.handleMouseUpLeave.bind(this));
    this.removeEventListener('mouseleave', this.handleMouseUpLeave.bind(this));
    this.removeEventListener('mousemove', this.handleMouseMove.bind(this));
    this.removeEventListener('touchstart', this.handleTouchStart.bind(this));
    this.removeEventListener('touchmove', this.handleTouchMove.bind(this));
    this.removeEventListener('touchend', this.handleTouchEnd.bind(this));
  }

  resizeImages() {
    const firstImage = this.figures[0].querySelectorAll('img')[0];
    const imageHeight = firstImage.clientHeight;
    this.firstImageWidth = firstImage.clientWidth;

    if (imageHeight === 0) {
      throw new Error('The image height is 0!');
    }

    for (const figure of Array.from(this.figures)) {
      const image = figure.querySelector('img');
      image.style.height = `${imageHeight}px`;
      image.style.width = '100%';
    }
  }

  updateHtml() {
    this.innerHTML = `
      <image-carousel-lightbox-button></image-carousel-lightbox-button>
      <image-carousel-nav-button data-variant="prev"></image-carousel-nav-button>
      <div class="carousel-images-scroller">
        <div class="carousel-images">
          ${Array.from(this.figures).map(figure => figure.outerHTML).join('')}
        </div>
      </div>
      <image-carousel-nav-button data-variant="next"></image-carousel-nav-button>
      <div class="carousel-dots">
        ${this.getDots()}
      </div>
    `;
  }

  handleMouseDown(event) {
    event.preventDefault();
    const scroller = this.querySelector('.carousel-images-scroller');
    scroller.style.cursor = 'grabbing';
    this.isDragging = true;
    this.startX = event.pageX - this.offsetLeft;
    this.scrollLeft = scroller.scrollLeft;
  }

  handleMouseUpLeave() {
    this.isDragging = false;
    const scroller = this.querySelector('.carousel-images-scroller');
    scroller.style.cursor = 'grab';
    this.snapToImage();
  }

  handleMouseMove(event) {
    if (!this.isDragging) {
      return;
    }

    event.preventDefault();
    const x = event.pageX - this.offsetLeft;
    const scrollSpeed = (x - this.startX) * 1.75;
    const scroller = this.querySelector('.carousel-images-scroller');
    scroller.scrollTo({ left: this.scrollLeft - scrollSpeed, behavior: 'auto' });
  }

  handleTouchStart(event) {
    this.isDragging = true;
    this.startX = event.touches[0].pageX - this.offsetLeft;
    const scroller = this.querySelector('.carousel-images-scroller');
    this.scrollLeft = scroller.scrollLeft;
  }

  handleTouchMove(event) {
    if (!this.isDragging) {
      return;
    }

    event.preventDefault();
    const x = event.touches[0].pageX - this.offsetLeft;
    const scrollSpeed = (x - this.startX) * 1.75;
    const scroller = this.querySelector('.carousel-images-scroller');
    scroller.scrollTo({ left: this.scrollLeft - scrollSpeed, behavior: 'auto' });
  }

  handleTouchEnd() {
    this.isDragging = false;
    this.snapToImage();
  }

  snapToImage() {
    const scroller = this.querySelector('.carousel-images-scroller');
    const scrollLeft = scroller.scrollLeft;
    const index = Math.round(scrollLeft / this.firstImageWidth);
    moveSlides(index, this);
  }

  getDots() {
    let html = '';

    if (this.figures.length > 5) {
      html += `${Number(this.dataset.index) + 1}&nbsp;/&nbsp;${this.figures.length}`;
    }
    else {
      for (let i = 0; i < this.figures.length; i++) {
        if (i === Number(this.dataset.index)) {
          html += `<span class="active"></span>`;
          continue;
        }

        html += `<span></span>`;
      }
    }

    return html;
  }

  updateDots() {
    const dots = this.querySelector('.carousel-dots');
    dots.innerHTML = this.getDots();
  }
}

customElements.define('image-carousel', ImageCarousel);
