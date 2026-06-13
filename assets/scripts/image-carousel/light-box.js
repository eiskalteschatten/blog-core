class LightBox extends HTMLElement {
  connectedCallback() {
    const imageSrc = this.dataset.imageSrc;
    const caption = this.dataset.caption || '';

    this.innerHTML = `
      <img src="${imageSrc}" alt="${caption}" class="lightbox-image">

      <button class="close-button">
        <span class="material-icons">close</span>
      </button>

      <div class="caption">${caption}</div>
    `;

    this.lightboxImage = this.querySelector('img');
    this.closeButton = this.querySelector('.close-button');
    this.closeButton.onclick = this.close.bind(this);
  }

  close() {
    this.remove();
  }
}

customElements.define('light-box', LightBox);

export function openLightbox(imageSrc, caption = '') {
  const lightbox = document.createElement('light-box');
  lightbox.dataset.imageSrc = imageSrc;
  lightbox.dataset.caption = caption || '';
  document.body.appendChild(lightbox);
}
