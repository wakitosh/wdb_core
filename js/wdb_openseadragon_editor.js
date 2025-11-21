/**
 * @file
 * Initializes OpenSeadragon and Annotorious v3 for the editor page,
 * and implements a custom annotation editor and API integration.
 */
(function ($, Drupal, OpenSeadragon, AnnotoriousOSD, drupalSettings, once) {
  'use strict';

  function createIiifTokenHelper(authConfig) {
    const config = authConfig || {};
    const token = (typeof config.token === 'string' && config.token.length) ? config.token : null;
    const paramName = (typeof config.param === 'string' && config.param.length)
      ? config.param
      : ((typeof config.parameter === 'string' && config.parameter.length) ? config.parameter : 'wdb_token');

    if (!token) {
      return {
        hasToken: false,
        appendToken: (url) => url,
        normalizeTileSources: (value) => value,
        attachViewer: () => { },
      };
    }

    const escapeRegex = (str) => str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const paramRegex = new RegExp(`([?&])${escapeRegex(paramName)}=([^&#]*)`);
    const encodedToken = encodeURIComponent(token);

    const appendToken = (url) => {
      if (!url || typeof url !== 'string') {
        return url;
      }
      const [basePart, fragment] = url.split('#', 2);
      let updated = basePart;
      if (paramRegex.test(basePart)) {
        updated = basePart.replace(paramRegex, `$1${paramName}=${encodedToken}`);
      }
      else {
        const separator = basePart.includes('?') ? '&' : '?';
        updated = `${basePart}${separator}${paramName}=${encodedToken}`;
      }
      return fragment ? `${updated}#${fragment}` : updated;
    };

    const normalizeTileSources = (value) => {
      if (!value) {
        return value;
      }
      if (Array.isArray(value)) {
        return value.map((entry) => (typeof entry === 'string') ? appendToken(entry) : entry);
      }
      if (typeof value === 'string') {
        return appendToken(value);
      }
      if (typeof value === 'object') {
        if (typeof value.url === 'string') {
          value.url = appendToken(value.url);
        }
        if (typeof value.tileSource === 'string') {
          value.tileSource = appendToken(value.tileSource);
        }
      }
      return value;
    };

    const patchViewerSources = (viewerInstance) => {
      if (!viewerInstance || typeof viewerInstance.addHandler !== 'function') {
        return;
      }
      const patchSources = () => {
        try {
          const world = viewerInstance.world;
          const count = world && typeof world.getItemCount === 'function' ? world.getItemCount() : 0;
          for (let idx = 0; idx < count; idx++) {
            const item = world.getItemAt(idx);
            const source = item && item.source;
            if (!source) {
              continue;
            }
            if (typeof source.url === 'string') {
              source.url = appendToken(source.url);
            }
            if (typeof source.tilesUrl === 'string') {
              source.tilesUrl = appendToken(source.tilesUrl);
            }
            if (typeof source.getTileUrl === 'function' && !source.__wdbTokenWrapped) {
              const original = source.getTileUrl.bind(source);
              source.getTileUrl = function patchedGetTileUrl(level, x, y, time) {
                const rawUrl = original(level, x, y, time);
                return appendToken(rawUrl);
              };
              source.__wdbTokenWrapped = true;
            }
          }
        }
        catch (e) {
          // Ignore patching failures.
        }
      };

      patchSources();
      viewerInstance.addHandler('open', () => { patchSources(); });
    };

    return {
      hasToken: true,
      appendToken,
      normalizeTileSources,
      attachViewer: patchViewerSources,
    };
  }

  // Shared tooltip reference for the editor page.
  let tooltip = null;
  // Track whether the pointer is currently inside the viewer area to avoid sticky UI.
  let isPointerInsideViewer = false;

  // Create the tooltip element once.
  function initTooltip() {
    if (!document.querySelector('.wdb-tooltip')) {
      tooltip = document.createElement('div');
      tooltip.className = 'wdb-tooltip';
      document.body.appendChild(tooltip);
    } else {
      tooltip = document.querySelector('.wdb-tooltip');
    }
  }

  /**
   * Drupal behavior to initialize the OpenSeadragon editor.
   */
  Drupal.behaviors.wdbOpenSeadragonEditor = {
    attach: function (context, settings) {
      once('openseadragon-editor-init', '#openseadragon-viewer', context).forEach(function (viewerElement) {
        if (settings.wdb_core && settings.wdb_core.openseadragon) {

          // Ensure tooltip DOM exists for this page.
          initTooltip();

          const osdSettings = settings.wdb_core.openseadragon;
          const iiifTokenHelper = createIiifTokenHelper(osdSettings.auth);
          osdSettings.tileSources = iiifTokenHelper.normalizeTileSources(osdSettings.tileSources);
          const viewer = OpenSeadragon({
            drawer: 'canvas',
            element: viewerElement,
            prefixUrl: osdSettings.prefixUrl,
            tileSources: osdSettings.tileSources,
            showNavigator: true,
            defaultZoomLevel: 0,
            minZoomLevel: 0.5,
            homeFillsViewer: true,
            crossOriginPolicy: 'Anonymous',
            gestureSettingsMouse: {
              clickToZoom: false,
            },
            // Prevent accidental zoom on touch / pen similar to viewer JS.
            gestureSettingsTouch: {
              clickToZoom: false,
              dblClickToZoom: false,
            },
            gestureSettingsPen: {
              clickToZoom: false,
              dblClickToZoom: false,
            },
            gestureSettingsUnknown: {
              clickToZoom: false,
            },
          });

          iiifTokenHelper.attachViewer(viewer);

          const anno = AnnotoriousOSD.createOSDAnnotator(viewer, {
            drawingEnabled: false,
            drawingMode: 'click',
          });
          anno.setDrawingTool('polygon');
          viewerElement.annotorious = anno;

          // --- Pointer in/out & tooltip helpers ---------------------------------
          const hideTooltip = () => {
            if (tooltip) tooltip.classList.remove('is-visible');
            viewerElement.style.cursor = 'default';
          };
          viewerElement.addEventListener('pointerenter', () => { isPointerInsideViewer = true; }, { passive: true });
          viewerElement.addEventListener('pointerleave', () => { isPointerInsideViewer = false; hideTooltip(); }, { passive: true });
          window.addEventListener('blur', () => { isPointerInsideViewer = false; hideTooltip(); });
          document.addEventListener('mouseleave', () => { isPointerInsideViewer = false; hideTooltip(); });

          // Show tooltip with label on hover over existing annotations.
          anno.on && anno.on('mouseEnterAnnotation', (annotation) => {
            // Optional: If actively drawing, you might want to skip showing tooltips.
            // const drawing = (typeof anno.isDrawingEnabled === 'function') && anno.isDrawingEnabled();
            // if (drawing) return;
            viewerElement.style.cursor = 'pointer';
            const commentBody = annotation.bodies && annotation.bodies.find(b => b.purpose === 'commenting');
            const labelText = commentBody ? commentBody.value : '';
            if (labelText && tooltip) {
              tooltip.textContent = labelText;
              tooltip.classList.add('is-visible');
              const geometry = annotation.target?.selector?.geometry;
              if (geometry && geometry.bounds) {
                const { maxX, maxY } = geometry.bounds;
                const viewerPoint = viewer.viewport.imageToViewerElementCoordinates(new OpenSeadragon.Point(maxX, maxY));
                const viewerRect = viewer.element.getBoundingClientRect();
                tooltip.style.top = `${window.scrollY + viewerRect.top + viewerPoint.y + 10}px`;
                tooltip.style.left = `${window.scrollX + viewerRect.left + viewerPoint.x + 10}px`;
              }
            }
          });
          anno.on && anno.on('mouseLeaveAnnotation', () => {
            viewerElement.style.cursor = 'default';
            if (tooltip) tooltip.classList.remove('is-visible');
          });

          // Track last primary pointer type to decide drawingMode (mouse: click, touch: drag).
          let lastPointerType = 'mouse';
          viewerElement.addEventListener('pointerdown', (ev) => {
            if (ev.isPrimary) {
              lastPointerType = ev.pointerType || 'mouse';
            }
          }, { passive: true });

          // Helper to set drawing mode if API exists; fallback to internal config.
          const setDesiredDrawingMode = (mode) => {
            try {
              if (anno.setDrawingMode) {
                anno.setDrawingMode(mode);
              }
              else if (anno._config) {
                anno._config.drawingMode = mode; // fallback (may depend on internal implementation)
              }
              // Flag on instance for reference.
              anno.__wdbDrawingMode = mode;
            } catch (e) {
              console.debug('Failed to set drawing mode dynamically:', e);
            }
          };

          // Mitigate unintended panning with pen in selection mode by disabling flick and (if supported) drag.
          const applyPenSelectionPanMitigation = () => {
            try {
              if (!anno.isDrawingEnabled || (typeof anno.isDrawingEnabled === 'function' && !anno.isDrawingEnabled())) {
                // Selection mode: reduce pen navigation sensitivity.
                if (viewer.gestureSettingsPen) {
                  viewer.gestureSettingsPen.flickEnabled = false;
                  // Some OpenSeadragon builds support dragToPan; if present, disable for pen.
                  if (Object.prototype.hasOwnProperty.call(viewer.gestureSettingsPen, 'dragToPan')) {
                    viewer.gestureSettingsPen.dragToPan = false;
                  }
                }
              } else {
                // Drawing mode re-enable panning if we disabled it.
                if (viewer.gestureSettingsPen) {
                  if (Object.prototype.hasOwnProperty.call(viewer.gestureSettingsPen, 'dragToPan')) {
                    viewer.gestureSettingsPen.dragToPan = true;
                  }
                }
              }
            } catch (e) {
              // Silently ignore if API changes.
            }
          };
          // Apply once initially.
          applyPenSelectionPanMitigation();

          // --- Add custom drawing tool buttons to the toolbar ---
          const panelToolbar = document.getElementById('wdb-panel-toolbar');
          // Inject a reusable style block (once) to disable selection when drawing.
          if (!document.getElementById('wdb-no-select-style')) {
            const styleTag = document.createElement('style');
            styleTag.id = 'wdb-no-select-style';
            styleTag.textContent = `
              .wdb-no-select, .wdb-no-select * {
                -webkit-user-select: none !important;
                user-select: none !important;
                -webkit-touch-callout: none !important;
                -webkit-tap-highlight-color: rgba(0,0,0,0) !important;
              }
            `;
            document.head.appendChild(styleTag);
          }
          if (panelToolbar) {
            const createToolButton = (title, svgPath, toolName) => {
              const button = document.createElement('button');
              button.type = 'button';
              button.title = title;
              button.classList.add('wdb-toolbar-button');
              if (toolName) {
                button.dataset.tool = toolName;
              }
              const svgIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
              svgIcon.setAttribute('viewBox', '0 0 24 24');
              svgIcon.innerHTML = svgPath;
              button.appendChild(svgIcon);
              return button;
            };
            const ICONS = {
              select: '<path d="M5.8889517,3.0758973v16.4799991c0,.5422223.44.9777781.9777777.9777781.28,0,.5511111-.1200002.7377778-.3333333l3.6711111-4.1999995,2.582222,5.1688881c.3511111.7022222,1.2044449.9866676,1.9066664.6355553s.986667-1.2044449.6355559-1.9066671l-2.5199992-5.053332h5.2488887c.5422223,0,.9822222-.4399999.9822222-.9822222,0-.2800001-.1200002-.5466665-.3288892-.7333336L7.6045072,2.3070084c-.1911111-.168889-.431111-.2622223-.6844444-.2622223-.5688889,0-1.0311112.4622223-1.0311111,1.0311112Z"/>',
              polygon: '<path d="M2.7143487,8.228547l18.8571432-3.0857142-1.7142855,13.7142854-12.6857147-2.4000003L2.7143487,8.228547Z"/><g><circle cx="2.7143487" cy="8.228547" r="1.7142856"/><circle cx="21.5714922" cy="5.1428328" r="1.7142861"/><circle cx="19.8572064" cy="18.8571181" r="1.7142855"/><circle cx="7.1714917" cy="16.4571192" r="1.7142855"/></g>',
            };

            const selectButton = createToolButton(Drupal.t('Select'), ICONS.select, 'select');
            const polygonButton = createToolButton(Drupal.t('Draw Polygon'), ICONS.polygon, 'polygon');
            // Order classes align with toolbar ordering spec.
            polygonButton.classList.add('order-draw');
            selectButton.classList.add('order-select');
            // Append so existing view/edit + prev/next + pager appear before them; CSS order values place them correctly.
            panelToolbar.appendChild(polygonButton);
            panelToolbar.appendChild(selectButton);

            const setActive = (tool) => {
              panelToolbar.querySelectorAll('[data-tool]').forEach(btn => btn.classList.remove('is-active'));
              const activeBtn = panelToolbar.querySelector(`[data-tool="${tool}"]`);
              if (activeBtn) {
                activeBtn.classList.add('is-active');
              }
            };

            // --- Pan suppression while drawing (touch/pen) ---
            const originalPanState = {
              panHorizontal: viewer.panHorizontal,
              panVertical: viewer.panVertical,
              gestureSettingsMouse: { ...viewer.gestureSettingsMouse },
              gestureSettingsTouch: viewer.gestureSettingsTouch ? { ...viewer.gestureSettingsTouch } : null,
              gestureSettingsPen: viewer.gestureSettingsPen ? { ...viewer.gestureSettingsPen } : null,
              gestureSettingsUnknown: viewer.gestureSettingsUnknown ? { ...viewer.gestureSettingsUnknown } : null,
            };
            let panSuppressed = false;
            const disableDragPan = (settings) => {
              if (!settings) return;
              if ('dragToPan' in settings) settings.dragToPan = false;
              if ('flickEnabled' in settings) settings.flickEnabled = false;
            };
            const enableDragPan = (settings, original) => {
              if (!settings || !original) return;
              Object.keys(original).forEach(k => { settings[k] = original[k]; });
            };
            const suppressPanForDrawing = () => {
              if (panSuppressed) return;
              panSuppressed = true;
              viewer.panHorizontal = false;
              viewer.panVertical = false;
              disableDragPan(viewer.gestureSettingsMouse);
              disableDragPan(viewer.gestureSettingsTouch);
              disableDragPan(viewer.gestureSettingsPen);
              disableDragPan(viewer.gestureSettingsUnknown);
            };
            const restorePanAfterDrawing = () => {
              if (!panSuppressed) return;
              panSuppressed = false;
              viewer.panHorizontal = originalPanState.panHorizontal;
              viewer.panVertical = originalPanState.panVertical;
              enableDragPan(viewer.gestureSettingsMouse, originalPanState.gestureSettingsMouse);
              enableDragPan(viewer.gestureSettingsTouch, originalPanState.gestureSettingsTouch);
              enableDragPan(viewer.gestureSettingsPen, originalPanState.gestureSettingsPen);
              enableDragPan(viewer.gestureSettingsUnknown, originalPanState.gestureSettingsUnknown);
            };

            // Click event for the select button.
            selectButton.addEventListener('click', (e) => {
              e.preventDefault();
              anno.setDrawingEnabled(false);
              // Update the UI state immediately.
              setActive('select');
              applyPenSelectionPanMitigation();
              restorePanAfterDrawing();
            });

            // Click event for the polygon button.
            polygonButton.addEventListener('click', (e) => {
              e.preventDefault();
              anno.setDrawingTool('polygon');
              // Decide drawing mode based on last pointer type / environment.
              const envPointer = lastPointerType;
              const isTouchLike = envPointer === 'touch' || (window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
              const mode = isTouchLike ? 'drag' : 'click';
              setDesiredDrawingMode(mode);
              anno.setDrawingEnabled(true);
              setActive('polygon');
              // In drawing mode allow pen panning again if previously disabled.
              applyPenSelectionPanMitigation();
              suppressPanForDrawing();
              // Disable text selection & native gestures during drawing.
              if (!viewerElement.__origTouchAction) viewerElement.__origTouchAction = viewerElement.style.touchAction;
              viewerElement.style.touchAction = 'none';
              viewerElement.classList.add('wdb-drawing-active');
              viewerElement.classList.add('wdb-no-select');
              // Some OSD inner elements can still become selectable; attempt to mark them too.
              const inner = viewerElement.querySelector('.openseadragon-canvas, canvas, .openseadragon-container');
              if (inner) { inner.classList.add('wdb-no-select'); }
            });

            anno.on('setDrawingTool', tool => setActive(tool));
            setActive('select');
            // If tool becomes polygon via other means, re-apply drawing mode heuristic.
            anno.on && anno.on('setDrawingTool', (tool) => {
              if (tool === 'polygon') {
                const envPointer = lastPointerType;
                const isTouchLike = envPointer === 'touch' || (window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
                const mode = isTouchLike ? 'drag' : 'click';
                setDesiredDrawingMode(mode);
                suppressPanForDrawing();
              }
              else if (tool === 'select') {
                restorePanAfterDrawing();
                // Restore touch-action when leaving drawing mode.
                if (viewerElement.__origTouchAction !== undefined) {
                  viewerElement.style.touchAction = viewerElement.__origTouchAction;
                } else {
                  viewerElement.style.touchAction = '';
                }
                viewerElement.classList.remove('wdb-drawing-active');
                viewerElement.classList.remove('wdb-no-select');
              }
            });
          }

          // --- Create DOM elements for the custom editor and confirmation modal ---
          const editorElement = document.createElement('div');
          editorElement.className = 'wdb-editor-popup';
          editorElement.innerHTML = `
            <div class="form-item">
              <textarea placeholder="Enter label..."></textarea>
            </div>
            <div class="form-actions">
              <div class="form-actions-left">
                <button class="button delete" data-action="delete" title="Delete"><svg viewBox="0 0 48 48"><defs><style> .a {fill: none; stroke: #000; stroke-linecap: round; stroke-linejoin: round; stroke-width: 2px; }</style></defs><line class="a" x1="9" y1="12" x2="39" y2="12"/><polyline class="a" points="17 12 20 8 28 8 31 12"/><polyline class="a" points="36 16 34.222 40 13.778 40 12 16"/><g><line class="a" x1="24" y1="18" x2="24" y2="32"/><line class="a" x1="30" y1="18" x2="30" y2="32"/><line class="a" x1="18" y1="18" x2="18" y2="32"/></g></svg></button>
              </div>
              <div class="form-actions-right">
                <button class="button button--danger" data-action="cancel">Cancel</button>
                <button class="button button--primary" data-action="save">OK</button>
              </div>
            </div>
          `;
          document.body.appendChild(editorElement);

          const confirmModalOverlay = document.createElement('div');
          confirmModalOverlay.className = 'wdb-confirm-modal-overlay';
          confirmModalOverlay.innerHTML = `
            <div class="wdb-confirm-modal">
              <p>${Drupal.t('Are you sure you want to delete this annotation?')}</p>
              <button class="button" data-action="confirm-cancel">Cancel</button>
              <button class="button button--primary" data-action="confirm-delete">Delete</button>
            </div>
          `;
          document.body.appendChild(confirmModalOverlay);

          const textarea = editorElement.querySelector('textarea');
          const saveButton = editorElement.querySelector('[data-action=save]');
          const cancelButton = editorElement.querySelector('[data-action=cancel]');
          const deleteButton = editorElement.querySelector('[data-action=delete]');
          const confirmDeleteButton = confirmModalOverlay.querySelector('[data-action=confirm-delete]');
          const confirmCancelButton = confirmModalOverlay.querySelector('[data-action=confirm-cancel]');

          let currentAnnotation = null;
          let originalAnnotationBeforeEdit = null;
          // Flag to track if a cancel operation is in progress.
          let isCancelling = false;

          const openEditor = (annotation, element) => {
            currentAnnotation = annotation;
            originalAnnotationBeforeEdit = JSON.parse(JSON.stringify(annotation));

            const body = annotation.bodies.find(b => b.purpose === 'commenting');
            textarea.value = body ? body.value : '';
            const bounds = element.getBoundingClientRect();
            editorElement.style.top = `${window.scrollY + bounds.top}px`;
            editorElement.style.left = `${window.scrollX + bounds.right + 5}px`;
            editorElement.style.display = 'block';
            textarea.focus();
          };

          const closeEditor = () => {
            editorElement.style.display = 'none';
            currentAnnotation = null;
            originalAnnotationBeforeEdit = null;
          };

          // ESC キーでポップアップを閉じる
          document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
              if (editorElement.style.display === 'block') {
                e.preventDefault();
                closeEditor();
              }
            }
          });

          saveButton.addEventListener('click', () => {
            if (currentAnnotation) {
              const newBodyValue = textarea.value.trim();
              if (!newBodyValue) {
                alert(Drupal.t('Label body cannot be empty.'));
                return;
              }

              const newBody = { purpose: 'commenting', value: newBodyValue };

              // Get the current live state of the annotation to reflect shape changes made by the user.
              const liveAnnotation = anno.getAnnotationById(currentAnnotation.id);

              const updatedAnnotation = {
                ...liveAnnotation,
                bodies: [newBody],
              };

              anno.updateAnnotation(updatedAnnotation);
            }
            closeEditor();
            anno.setSelected(undefined);
          });

          cancelButton.addEventListener('click', () => {
            if (currentAnnotation) {
              const isNew = originalAnnotationBeforeEdit.bodies.length === 0;

              if (isNew) {
                anno.removeAnnotation(currentAnnotation.id);
              }
              else {
                // Set the cancelling flag before overwriting with the original state.
                isCancelling = true;
                anno.updateAnnotation(originalAnnotationBeforeEdit);
                anno.cancelSelected(currentAnnotation.id);
              }
            }
            closeEditor();
          });

          deleteButton.addEventListener('click', () => {
            if (currentAnnotation) {
              confirmModalOverlay.style.display = 'flex';
            }
          });

          confirmCancelButton.addEventListener('click', () => {
            confirmModalOverlay.style.display = 'none';
          });

          confirmDeleteButton.addEventListener('click', () => {
            if (currentAnnotation) {
              anno.removeAnnotation(currentAnnotation.id);
            }
            confirmModalOverlay.style.display = 'none';
            closeEditor();
          });

          // --- Connect Annotorious events with the editor ---
          anno.on('selectionChanged', (selected) => {
            const selection = selected.length > 0 ? selected[0] : null;

            // If selection is cleared.
            if (!selection) {
              // If deselection occurs while still in drawing mode (e.g. touch single-tap closure),
              // do NOT auto-remove a brand new annotation; wait for createAnnotation / user action.
              if (currentAnnotation) {
                const isNew = originalAnnotationBeforeEdit.bodies.length === 0;
                const drawingActive = (typeof anno.isDrawingEnabled === 'function') ? anno.isDrawingEnabled() : true;
                if (!(isNew && drawingActive)) {
                  // Proceed with prior cleanup logic only if not a fresh drawing being finalized.
                  if (isNew) {
                    anno.removeAnnotation(currentAnnotation.id);
                  } else {
                    anno.updateAnnotation(originalAnnotationBeforeEdit);
                  }
                  closeEditor();
                }
              } else {
                closeEditor();
              }
              return;
            }

            // If something was selected.
            // If we were already editing something else, cancel it.
            if (currentAnnotation && currentAnnotation.id !== selection.id) {
              const isNew = originalAnnotationBeforeEdit.bodies.length === 0;
              if (isNew) {
                anno.removeAnnotation(currentAnnotation.id);
              }
              else {
                anno.updateAnnotation(originalAnnotationBeforeEdit);
              }
            }

            // Open the editor for the new selection.
            const element = viewer.element.querySelector('.a9s-annotation.selected');
            if (element) {
              openEditor(selection, element);
            }
          });

          // --- Touch fallback: some environments fail to emit selectionChanged on first tap ---
          viewerElement.addEventListener('pointerup', (ev) => {
            if (ev.pointerType !== 'touch') return;
            // Let Annotorious process the pointer then inspect current selection.
            setTimeout(() => {
              try {
                if (currentAnnotation) return; // already open
                if (typeof anno.getSelected === 'function') {
                  const sel = anno.getSelected();
                  if (sel && sel.length > 0) {
                    const first = sel[0];
                    const element = viewer.element.querySelector('.a9s-annotation.selected');
                    if (element) {
                      openEditor(first, element);
                    }
                  }
                }
              } catch (e) {
                // silent
              }
            }, 10);
          }, { passive: true });

          // Reinforce no-selection on iOS during active drawing pointer interactions.
          viewerElement.addEventListener('pointerdown', (ev) => {
            if (ev.pointerType === 'touch') {
              const drawing = (typeof anno.isDrawingEnabled === 'function') ? anno.isDrawingEnabled() : true;
              if (drawing) {
                viewerElement.classList.add('wdb-no-select');
              }
            }
          }, { passive: true });

          // --- Open editor immediately after annotation creation for touch (if not already opened) ---
          if (anno.on) {
            anno.on('createAnnotation', (annotation) => {
              if (lastPointerType !== 'touch') return;
              if (currentAnnotation) return; // already editing
              // Attempt to locate the element; fallback to selected class.
              const element = viewer.element.querySelector('.a9s-annotation.selected') || viewer.element.querySelector('.a9s-annotation:last-child');
              if (element) {
                openEditor(annotation, element);
              }
            });
          }

          // --- API Integration ---
          viewer.addHandler('open', () => {
            if (osdSettings.annotationListUrl) {
              const maybeSelectFromUrl = () => {
                try {
                  const params = new URLSearchParams(window.location.search);
                  const highlightId = params.get('highlight_annotation');
                  if (!highlightId) return;
                  const exists = anno.getAnnotationById(highlightId);
                  if (exists) {
                    try { anno.setSelected(highlightId); } catch (e) { }
                    try {
                      const { bounds } = exists.target.selector.geometry || {};
                      if (bounds) {
                        const cx = bounds.minX + (bounds.maxX - bounds.minX) / 2;
                        const cy = bounds.minY + (bounds.maxY - bounds.minY) / 2;
                        const center = viewer.viewport.imageToViewportCoordinates(new OpenSeadragon.Point(cx, cy));
                        viewer.viewport.panTo(center, false);
                      }
                    } catch (e) { }
                  }
                } catch (e) { }
              };

              const load = anno.loadAnnotations(osdSettings.annotationListUrl);
              if (load && typeof load.then === 'function') {
                load.then(() => maybeSelectFromUrl());
              } else {
                maybeSelectFromUrl();
              }
            }
          });

          // The 'updateAnnotation' event handles both creating new and updating existing annotations.
          anno.on('updateAnnotation', (annotation, previous) => {
            // If this update was triggered by a cancel operation, do not call the API.
            if (isCancelling) {
              isCancelling = false;
              return;
            }

            const commentBody = annotation.bodies.find(b => b.purpose === 'commenting');
            if (!commentBody || !(commentBody.value || '').trim()) {
              anno.updateAnnotation(previous.id, previous);
              return;
            }
            if (osdSettings.initialCanvasID) {
              annotation.target.source = osdSettings.initialCanvasID;
            }

            const isNew = !previous.bodies.find(b => b.purpose === 'commenting');
            //            const endpoint = isNew ? '/create' : '/update';
            //            const apiUrl = osdSettings.annotationEndpoint.url + endpoint;
            const apiUrl = isNew ? osdSettings.annotationEndpoint.create : osdSettings.annotationEndpoint.update;

            fetch(apiUrl, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(annotation),
            })
              .then(response => response.ok ? response.json() : Promise.reject(new Error(response.statusText)))
              .then(savedAnnotation => {
                if (isNew) {
                  // Replace the temporary client-side annotation with the permanent one from the server.
                  anno.removeAnnotation(annotation.id);
                  anno.addAnnotation(savedAnnotation);
                  anno.setDrawingTool('polygon');
                  anno.setDrawingEnabled(true);
                }
              })
              .catch(error => {
                console.error(`Error ${isNew ? 'creating' : 'updating'} annotation:`, error);
                anno.updateAnnotation(previous.id, previous);
              });
          });

          anno.on('deleteAnnotation', annotation => {
            // This event only fires for existing annotations being deleted.
            const isNew = !annotation.id.startsWith('http');
            if (isNew) {
              // This was just an unsaved new annotation being deleted, so no API call is needed.
              return;
            }
            const deleteUrl = `${osdSettings.annotationEndpoint.destroy}?uri=${encodeURIComponent(annotation.id)}`;
            fetch(deleteUrl, { method: 'DELETE' })
              .then(response => {
                if (!response.ok) {
                  throw new Error(response.statusText);
                }
              })
              .catch(error => {
                console.error('Error deleting annotation:', error);
                alert('Error deleting annotation.');
                // Re-add the annotation if the API call fails.
                anno.addAnnotation(annotation);
              });
          });
        }
      });
    },
  };
})(jQuery, Drupal, window.OpenSeadragon, window.AnnotoriousOSD, drupalSettings, once);
