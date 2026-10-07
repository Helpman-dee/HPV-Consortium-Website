(() => {
  const directorySection = document.querySelector('#team');
  if (!directorySection) return;

  const path = window.location.pathname.toLowerCase();
  const group = path.endsWith('/staff.html') ? 'staff' : path.endsWith('/sab.html') ? 'board' : 'investigators';
  const createElement = (tagName, className, text) => {
    const element = document.createElement(tagName);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
  };

  const socialIcon = (url) => {
    let host = '';
    try {
      host = new URL(url).hostname.toLowerCase();
    } catch {
      return 'bi bi-link-45deg';
    }
    if (host.includes('linkedin')) return 'bi bi-linkedin';
    if (host.includes('facebook')) return 'bi bi-facebook';
    if (host.includes('twitter') || host.includes('x.com')) return 'bi bi-twitter-x';
    return 'bi bi-link-45deg';
  };

  const renderDirectory = (data) => {
    if (!data.managed || !Array.isArray(data.people)) return;

    const title = directorySection.querySelector('.section-title');
    const container = createElement('div', 'container managed-directory');
    const row = createElement('div', 'row g-4');
    const featuredPerson = group === 'staff' ? null : createElement('div', 'content managed-featured-person');

    data.people.forEach((person, index) => {
      const identity = String(person.id || person.name || index).replace(/[^A-Za-z0-9_-]/g, '-');
      const modalId = `managed-bio-${group}-${identity}`;
      const isFeatured = Boolean(featuredPerson && index === 0);
      const column = isFeatured ? null : createElement('article', 'col-lg-3 col-md-6 mb-4 managed-person');
      const card = createElement('div', 'person');
      const figure = createElement('figure');
      const modalTriggers = [];

      const makeModalTrigger = (element, label) => {
        element.type = 'button';
        element.setAttribute('data-bs-target', `#${modalId}`);
        element.setAttribute('aria-label', label);
        modalTriggers.push(element);
      };

      if (person.image) {
        const photoButton = createElement('button', 'managed-person-trigger');
        makeModalTrigger(photoButton, `View biography for ${person.name || 'this person'}`);
        const image = createElement('img', 'img-fluid border');
        image.src = person.image;
        image.alt = person.name || '';
        image.loading = 'lazy';
        photoButton.append(image);
        figure.append(photoButton);
      }
      if (Array.isArray(person.social) && person.social.length) {
        const social = createElement('div', 'social managed-person-social');
        person.social.forEach((url) => {
          const link = createElement('a');
          link.href = url;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
          link.setAttribute('aria-label', 'Open profile');
          link.title = 'Open profile';
          link.append(createElement('span', socialIcon(url)));
          social.append(link);
        });
        figure.append(social);
      }

      const contents = createElement('div', 'person-contents');
      const nameHeading = createElement(group === 'staff' ? 'h4' : 'h3', 'managed-person-heading');
      const nameButton = createElement('button', 'managed-person-name', person.name || '');
      makeModalTrigger(nameButton, `View biography for ${person.name || 'this person'}`);
      nameHeading.append(nameButton);
      contents.append(nameHeading);
      if (person.role) contents.append(createElement('span', 'position', person.role));
      card.append(figure, contents);
      if (isFeatured) {
        featuredPerson.append(card);
      } else {
        column.append(card);
        row.append(column);
      }

      const modal = createElement('div', 'modal fade');
      modal.id = modalId;
      modal.tabIndex = -1;
      modal.setAttribute('aria-labelledby', `${modalId}-label`);
      modal.setAttribute('aria-hidden', 'true');
      const dialog = createElement('div', 'modal-dialog');
      const modalContent = createElement('div', 'modal-content');
      const modalHeader = createElement('div', 'modal-header');
      const modalTitle = createElement('h5', 'modal-title', person.name || 'Biography');
      modalTitle.id = `${modalId}-label`;
      const closeButton = createElement('button', 'btn-close');
      closeButton.type = 'button';
      closeButton.setAttribute('data-bs-dismiss', 'modal');
      closeButton.setAttribute('aria-label', 'Close');
      modalHeader.append(modalTitle, closeButton);

      const modalBody = createElement('div', 'modal-body');
      if (person.image) {
        const portrait = createElement('img', 'img-fluid mb-3 d-block mx-auto');
        portrait.src = person.image;
        portrait.alt = person.name || '';
        modalBody.append(portrait);
      }
      if (person.role) modalBody.append(createElement('p', 'managed-person-modal-role', person.role));
      const bioText = String(person.bio || '').trim();
      if (bioText) {
        bioText.split(/\r?\n\s*\r?\n/).filter((paragraph) => paragraph.trim()).forEach((paragraph) => {
          const bioParagraph = createElement('p', 'managed-person-modal-bio');
          bioParagraph.textContent = paragraph.trim();
          modalBody.append(bioParagraph);
        });
      } else {
        modalBody.append(createElement('p', '', 'Biography not provided.'));
      }
      if (Array.isArray(person.social) && person.social.length) {
        const profileLinks = createElement('div', 'managed-person-modal-links');
        person.social.forEach((url) => {
          const link = createElement('a', 'btn btn-outline-secondary btn-sm', 'Profile');
          link.href = url;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
          profileLinks.append(link);
        });
        modalBody.append(profileLinks);
      }

      const modalFooter = createElement('div', 'modal-footer');
      const footerClose = createElement('button', 'btn btn-secondary', 'Close');
      footerClose.type = 'button';
      footerClose.setAttribute('data-bs-dismiss', 'modal');
      modalFooter.append(footerClose);
      [closeButton, footerClose].forEach((button) => {
        button.addEventListener('click', () => {
          window.bootstrap.Modal.getOrCreateInstance(modal).hide();
        });
      });
      modalContent.append(modalHeader, modalBody, modalFooter);
      dialog.append(modalContent);
      modal.append(dialog);
      document.body.append(modal);
      modalTriggers.forEach((trigger) => {
        trigger.addEventListener('click', () => {
          window.bootstrap.Modal.getOrCreateInstance(modal).show();
        });
      });
    });

    if (!data.people.length) {
      row.append(createElement('p', 'managed-directory-empty', 'No profiles are currently listed.'));
    }
    container.append(row);
    const content = title ? [title] : [];
    if (featuredPerson?.childElementCount) content.push(featuredPerson);
    content.push(container);
    directorySection.replaceChildren(...content);
  };

  fetch(`site-content-api.php?group=${encodeURIComponent(group)}`, { headers: { Accept: 'application/json' } })
    .then((response) => {
      if (!response.ok) throw new Error('Directory feed unavailable');
      return response.json();
    })
    .then(renderDirectory)
    .catch(() => {});
})();