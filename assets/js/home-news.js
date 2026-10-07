(() => {
  const list = document.querySelector('#home-news-list');
  if (!list) return;

  const emptyState = document.querySelector('#home-news-empty');
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

  fetch('news-api.php', { headers: { Accept: 'application/json' } })
    .then((response) => {
      if (!response.ok) throw new Error('News feed unavailable');
      return response.json();
    })
    .then((posts) => {
      if (!Array.isArray(posts) || !posts.length) return;
      list.replaceChildren();
      posts.slice(0, 3).forEach((post) => {
        const column = createElement('article', 'col-md-6 col-xl-4 home-news-item');
        const link = `news.html?story=${encodeURIComponent(post.slug)}`;
        const imageLink = createElement('a', 'home-news-image');
        imageLink.href = link;
        const image = createElement('img');
        image.src = post.image;
        image.alt = post.title;
        image.loading = 'lazy';
        imageLink.append(image);

        const body = createElement('div', 'home-news-copy');
        body.append(createElement('p', 'home-news-category', post.category));
        const heading = createElement('h3');
        const title = createElement('a', '', post.title);
        title.href = link;
        heading.append(title);
        body.append(heading, createElement('p', 'home-news-date', formatDate(post.date)), createElement('p', 'home-news-excerpt', post.excerpt));
        column.append(imageLink, body);
        list.append(column);
      });
      if (emptyState) emptyState.hidden = true;
    })
    .catch(() => {});
})();