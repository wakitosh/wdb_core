/**
 * @file
 * Handles Ajax submission for the WDB search form and displays rich, interactive results.
 */
(function ($, Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Performs the search via Ajax and updates the results container.
   *
   * @param {HTMLElement} form
   * The search form element.
   * @param {number} [page=0]
   * The page number for pagination.
   */
  function performSearch(form, page = 0) {
    const realizedForm = $(form).find('input[name="realized_form"]').val();
    const realizedFormOp = $(form).find('select[name="realized_form_op"]').val();
    const basicForm = $(form).find('input[name="basic_form"]').val();
    const basicFormOp = $(form).find('select[name="basic_form_op"]').val();
    const signCode = $(form).find('input[name="sign"]').val();
    const signOp = $(form).find('select[name="sign_op"]').val();
    const operator = $(form).find('input[name="operator"]:checked').val();
    const subsystem = $(form).find('select[name="subsystem"]').val();
    const lexicalCategory = $(form).find('select[name="lexical_category"]').val();
    const includeChildren = $(form).find('input[name="include_children"]').is(':checked');
    const limit = $(form).find('select[name="limit"]').val();

    const params = new URLSearchParams();
    if (realizedForm) {
      params.append('realized_form', realizedForm);
      params.append('realized_form_op', realizedFormOp);
    }
    if (basicForm) {
      params.append('basic_form', basicForm);
      params.append('basic_form_op', basicFormOp);
    }
    if (signCode) {
      params.append('sign', signCode);
      params.append('sign_op', signOp);
    }
    if (operator) {
      params.append('op', operator);
    }
    if (subsystem) {
      params.append('subsystem', subsystem);
    }
    if (lexicalCategory) {
      params.append('lexical_category', lexicalCategory);
      if (includeChildren) {
        params.append('include_children', '1');
      }
    }
    params.append('page', page);
    params.append('limit', limit);

    const resultsContainer = $('#wdb-search-results-container');
    const pagerContainer = $('#wdb-search-pager-container');

    // Do not perform a search if no parameters are set (except for pagination).
    if (params.toString() === `page=${page}&limit=${limit}`) {
      resultsContainer.html($('<p>').text(Drupal.t('Please enter at least one search condition.')));
      return;
    }

    const apiUrl = Drupal.url(`wdb/api/search?${params.toString()}`);

    const throbber = '<div class="ajax-progress ajax-progress-throbber"><div class="throbber">&nbsp;</div></div>';
    resultsContainer.html(throbber);
    pagerContainer.empty();

    $.get(apiUrl)
      .done(function (response) {
        resultsContainer.empty();
        const results = response.results || [];
        const total = response.total || 0;
        const limit = response.limit || 50;
        const currentPage = response.page || 0;

        resultsContainer.append($('<h3>').text(Drupal.t('Found @count results', { '@count': total })));

        if (results.length > 0) {
          const resultList = $('<ul>').addClass('wdb-search-results');
          results.forEach(function (item, index) {

            const resultIndex = (currentPage * limit) + index + 1;
            const numberSpan = $('<span>').addClass('search-result-number').text(`${resultIndex}. `);
            // Normalize highlight_annotation in the URL to be path-only so it works across domains.
            let hrefVal = item.link || '#';
            try {
              const u = new URL(hrefVal, window.location.origin);
              const ha = u.searchParams.get('highlight_annotation');
              if (ha) {
                try {
                  const hau = new URL(ha, window.location.origin);
                  let p = hau.pathname || ha;
                  if (p.length > 1 && p.endsWith('/')) p = p.slice(0, -1);
                  u.searchParams.set('highlight_annotation', p);
                  hrefVal = u.toString();
                } catch (_) {
                  // If not a full URL, keep as-is
                }
              }
            } catch (_) { /* keep original */ }
            const link = $('<a>').attr('href', hrefVal).attr('target', '_blank').text(`${item.realized_form} (${item.basic_form})`);

            // Add lexical category information.
            const lexicalCategoryInfo = $('<span>').addClass('lexical-category-info').text(`[${item.lexical_category}]`);
            const linkWrapper = $('<div>').addClass('result-link-wrapper').append(link).append(' ').append(lexicalCategoryInfo);

            const sourceInfo = $('<p>').addClass('source-info').text(`${item.source}, p. ${item.page}`);

            const thumbnailContainer = $('<div>').addClass('search-result-thumbnail');
            if (item.thumbnail_data && item.thumbnail_data.image_url) {
              const img = $('<img>').attr('src', item.thumbnail_data.image_url);
              const svgOverlay = $(document.createElementNS('http://www.w3.org/2000/svg', 'svg'))
                .attr('viewBox', `0 0 ${item.thumbnail_data.region_w} ${item.thumbnail_data.region_h}`)
                .addClass('thumbnail-overlay');
              thumbnailContainer.append(img).append(svgOverlay);
            }

            const signsList = $('<ul>').addClass('constituent-signs-list');
            if (item.constituent_signs && item.constituent_signs.length > 0) {
              item.constituent_signs.forEach(sign => {
                const signText = sign.phone ? `${sign.sign_code} [${sign.phone}]` : sign.sign_code;
                const signItem = $('<li>')
                  .addClass('constituent-sign-item')
                  .text(signText)
                  .data('points', sign.polygon_points)
                  .data('region_x', item.thumbnail_data.region_x)
                  .data('region_y', item.thumbnail_data.region_y);
                signsList.append(signItem);
              });
            }

            const textContainer = $('<div>').addClass('search-result-text').append(linkWrapper).append(sourceInfo).append(signsList);
            const listItem = $('<li>').addClass('wdb-search-result-item').append(numberSpan).append(thumbnailContainer).append(textContainer);
            resultList.append(listItem);
          });
          resultsContainer.append(resultList);

          Drupal.attachBehaviors(resultsContainer[0]);

          // Build and append the pager.
          const numPages = Math.ceil(total / limit);
          if (numPages > 1) {
            const pager = $('<ul>').addClass('pager__items js-pager__items');
            for (let i = 0; i < numPages; i++) {
              const li = $('<li>').addClass('pager__item');
              if (i === currentPage) {
                li.addClass('is-active').append($('<span>').addClass('pager__link is-active').text(i + 1));
              }
              else {
                li.append($('<a>').addClass('pager__link').attr('href', '#').text(i + 1).data('page', i));
              }
              pager.append(li);
            }
            pagerContainer.append(pager);
          }
        }
        else {
          resultsContainer.append($('<p>').text('No results found.'));
        }
      })
      .fail(function () {
        resultsContainer.html($('<p>').text('An error occurred during the search.'));
      });
  }

  /**
   * Drupal behavior for the WDB search form.
   */
  Drupal.behaviors.wdbSearch = {
    attach: function (context, settings) {
      // Use event delegation to bind all events to the form just once.
      once('wdb-search-form-events', '#wdb-core-search-form', context).forEach(function (form) {

        // 1. Form submission event.
        $(form).on('submit', function (event) {
          event.preventDefault();
          performSearch(this, 0);
        });

        // 2. Pager click event.
        // This is delegated to the form so it works on the dynamically added pager.
        $(form).on('click', '#wdb-search-pager-container a', function (event) {
          event.preventDefault();
          performSearch(form, $(this).data('page'));
        });

        // 3. Hover event for constituent signs.
        // This is delegated to the form for dynamically added results.
        $(form).on('mouseenter', '.constituent-sign-item', function () {
          const signItem = $(this);
          const points = signItem.data('points');
          const regionX = signItem.data('region_x');
          const regionY = signItem.data('region_y');
          const svgOverlay = signItem.closest('.wdb-search-result-item').find('.thumbnail-overlay');

          if (points && svgOverlay.length) {
            const relativePoints = points.map(p_str => {
              const coords = p_str.split(',');
              return `${coords[0] - regionX},${coords[1] - regionY}`;
            }).join(' ');

            const polygon = $(document.createElementNS('http://www.w3.org/2000/svg', 'polygon'))
              .attr('points', relativePoints)
              .addClass('highlight-polygon');
            svgOverlay.append(polygon);
          }
        }).on('mouseleave', '.constituent-sign-item', function () {
          $(this).closest('.wdb-search-result-item').find('.highlight-polygon').remove();
        });

        // Automatically perform a search on page load if initial parameters exist.
        if (settings.wdb_core && settings.wdb_core.search && settings.wdb_core.search.initial_params) {
          performSearch(form, settings.wdb_core.search.initial_params.page || 0);
          // Delete the setting after running once to prevent re-execution on AJAX rebuilds.
          delete settings.wdb_core.search.initial_params;
        }
      });
    },
  };
})(jQuery, Drupal, drupalSettings, once);
