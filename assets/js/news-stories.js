(() => {
  const main = document.querySelector('main.main');
  if (!main) return;

  const storyUrl = new URL(window.location.href);
  const selectedSlug = storyUrl.searchParams.get('story');

  const createElement = (tagName, className, text) => {
    const element = document.createElement(tagName);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
  };

  const formatDate = (value) => {
    const date = new Date(`${value}T00:00:00`);
    return Number.isNaN(date.getTime())
      ? value
      : date.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
  };

  const appendVideo = (article, post) => {
    if (!/^[A-Za-z0-9_-]{11}$/.test(post.videoId || '')) return;

    const trigger = createElement('button', 'news-video-trigger');
    trigger.type = 'button';
    trigger.setAttribute('aria-label', `Play video for ${post.title}`);
    const thumbnail = createElement('img');
    thumbnail.src = `https://img.youtube.com/vi/${post.videoId}/hqdefault.jpg`;
    thumbnail.alt = '';
    thumbnail.loading = 'lazy';
    const playIcon = createElement('span', 'news-video-play');
    playIcon.append(createElement('i', 'bi bi-play-fill'));
    trigger.append(thumbnail, playIcon);

    const modal = createElement('div', 'modal fade');
    modal.id = 'news-video-modal';
    modal.tabIndex = -1;
    modal.setAttribute('aria-hidden', 'true');
    const dialog = createElement('div', 'modal-dialog modal-lg modal-dialog-centered');
    const content = createElement('div', 'modal-content');
    const header = createElement('div', 'modal-header');
    header.append(createElement('h2', 'modal-title fs-5', post.title));
    const closeButton = createElement('button', 'btn-close');
    closeButton.type = 'button';
    closeButton.setAttribute('data-bs-dismiss', 'modal');
    closeButton.setAttribute('aria-label', 'Close video');
    header.append(closeButton);
    const modalBody = createElement('div', 'modal-body p-0');
    const ratio = createElement('div', 'ratio ratio-16x9');
    const iframe = createElement('iframe');
    iframe.title = post.title;
    iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
    iframe.allowFullscreen = true;
    iframe.referrerPolicy = 'strict-origin-when-cross-origin';
    ratio.append(iframe);
    modalBody.append(ratio);
    content.append(header, modalBody);
    dialog.append(content);
    modal.append(dialog);

    trigger.addEventListener('click', () => {
      iframe.src = `https://www.youtube-nocookie.com/embed/${post.videoId}?autoplay=1`;
      window.bootstrap.Modal.getOrCreateInstance(modal).show();
    });
    modal.addEventListener('hide.bs.modal', () => {
      if (modal.contains(document.activeElement)) document.activeElement.blur();
      iframe.src = '';
    });
    modal.addEventListener('hidden.bs.modal', () => {
      iframe.src = '';
      trigger.focus();
    });

    article.append(trigger);
    document.body.append(modal);
  };

  const appendShareLinks = (article, post) => {
    const shareUrl = new URL(`news.html?story=${encodeURIComponent(post.slug)}`, window.location.href).toString();
    const encodedUrl = encodeURIComponent(shareUrl);
    const encodedTitle = encodeURIComponent(post.title);
    const share = createElement('section', 'news-share');
    share.setAttribute('aria-label', 'Share this story');
    share.append(createElement('h2', '', 'Share this story'));
    const actions = createElement('div', 'news-share-actions');
    const shareTargets = [
      ['Facebook', 'bi bi-facebook', `https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`],
      ['WhatsApp', 'bi bi-whatsapp', `https://wa.me/?text=${encodedTitle}%20${encodedUrl}`],
      ['LinkedIn', 'bi bi-linkedin', `https://www.linkedin.com/sharing/share-offsite/?url=${encodedUrl}`],
      ['X', 'bi bi-twitter-x', `https://twitter.com/intent/tweet?url=${encodedUrl}&text=${encodedTitle}`],
    ];

    shareTargets.forEach(([label, icon, url]) => {
      const link = createElement('a', 'news-share-link');
      link.href = url;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.setAttribute('aria-label', `Share on ${label}`);
      link.append(createElement('i', icon), document.createTextNode(` ${label}`));
      actions.append(link);
    });

    const copyButton = createElement('button', 'news-share-link news-copy-link');
    copyButton.type = 'button';
    copyButton.append(createElement('i', 'bi bi-link-45deg'), document.createTextNode(' Copy link'));
    const copyStatus = createElement('span', 'news-share-status');
    copyStatus.setAttribute('role', 'status');
    copyButton.addEventListener('click', async () => {
      try {
        if (navigator.clipboard?.writeText) {
          await navigator.clipboard.writeText(shareUrl);
        } else {
          const input = createElement('textarea');
          input.value = shareUrl;
          input.style.position = 'fixed';
          input.style.opacity = '0';
          document.body.append(input);
          input.select();
          const copied = document.execCommand('copy');
          input.remove();
          if (!copied) throw new Error('Copy failed');
        }
        copyStatus.textContent = 'Link copied';
      } catch {
        copyStatus.textContent = 'Copy unavailable';
      }
    });
    actions.append(copyButton, copyStatus);
    share.append(actions);
    article.append(share);
  };

  const renderStory = (post) => {
    const section = createElement('section', 'news-article section');
    const container = createElement('div', 'container news-article-container');
    const backLink = createElement('a', 'news-article-back');
    backLink.href = 'news.html#latest-stories';
    backLink.append(createElement('i', 'bi bi-arrow-left'), document.createTextNode(' All stories'));
    container.append(backLink);

    const article = createElement('article', 'news-article-content');
    const category = createElement('p', 'news-kicker', post.category);
    const title = createElement('h1', '', post.title);
    const metaParts = [formatDate(post.date)];
    if (post.author) metaParts.push(`By ${post.author}`);
    const meta = createElement('p', 'news-article-meta', metaParts.join(' | '));
    const image = createElement('img', 'news-article-image');
    image.src = post.image;
    image.alt = post.title;
    image.loading = 'eager';

    const body = createElement('div', 'news-article-body');
    post.body.split(/\r?\n\s*\r?\n/).filter((paragraph) => paragraph.trim()).forEach((paragraph) => {
      body.append(createElement('p', '', paragraph.trim()));
    });

    article.append(category, title, meta, image);
    appendVideo(article, post);
    if (post.excerpt) article.append(createElement('p', 'news-article-lede', post.excerpt));
    article.append(body);
    appendShareLinks(article, post);
    container.append(article);
    section.append(container);
    main.replaceChildren(section);
    document.title = `${post.title} | HPV Consortium`;
  };

  const renderMissingStory = () => {
    const section = createElement('section', 'news-article section');
    const container = createElement('div', 'container news-article-container');
    const title = createElement('h1', '', 'Story not found');
    const link = createElement('a', 'news-article-back');
    link.href = 'news.html#latest-stories';
    link.append(createElement('i', 'bi bi-arrow-left'), document.createTextNode(' Return to all stories'));
    container.append(title, link);
    section.append(container);
    main.replaceChildren(section);
    document.title = 'Story not found | HPV Consortium';
  };

  const renderPostList = (posts) => {
    const storyGrid = document.querySelector('#latest-news-list');
    const emptyState = document.querySelector('#latest-news-empty');
    if (!storyGrid) return;
    storyGrid.replaceChildren();
    if (!posts.length) {
      if (emptyState) emptyState.hidden = false;
      return;
    }
    if (emptyState) emptyState.hidden = true;

    posts.forEach((post) => {
      const column = createElement('article', 'col-md-6 col-xl-4 news-story');
      const storyLink = `news.html?story=${encodeURIComponent(post.slug)}`;
      const imageLink = createElement('a', 'news-story-image');
      imageLink.href = storyLink;
      imageLink.setAttribute('aria-label', `Read ${post.title}`);
      const image = createElement('img', '', '');
      image.src = post.image;
      image.alt = post.title;
      image.loading = 'lazy';
      imageLink.append(image, createElement('span', 'news-story-category', post.category));

      const storyBody = createElement('div', 'news-story-body');
      storyBody.append(createElement('p', 'news-story-label', post.category.toUpperCase()));
      const heading = createElement('h3');
      const headingLink = createElement('a', '', post.title);
      headingLink.href = storyLink;
      heading.append(headingLink);
      storyBody.append(heading);
      storyBody.append(createElement('p', 'news-story-date', formatDate(post.date)));
      storyBody.append(createElement('p', '', post.excerpt));
      const readLink = createElement('a', 'news-read-link', 'Read the story');
      readLink.href = storyLink;
      readLink.append(createElement('i', 'bi bi-arrow-right'));
      storyBody.append(readLink);
      column.append(imageLink, storyBody);
      storyGrid.append(column);
    });
  };

  fetch('news-api.php', { headers: { Accept: 'application/json' } })
    .then((response) => {
      if (!response.ok) throw new Error('News feed unavailable');
      return response.json();
    })
    .then((posts) => {
      if (!Array.isArray(posts)) throw new Error('Invalid news feed');
      if (selectedSlug !== null) {
        const post = posts.find((item) => item.slug === selectedSlug);
        if (post) renderStory(post);
        else renderMissingStory();
        return;
      }
      renderPostList(posts);
    })
    .catch(() => {
      if (selectedSlug !== null) renderMissingStory();
    });
})();