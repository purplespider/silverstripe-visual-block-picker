/**
 * Visual Block Picker
 *
 * Replaces Elemental's narrow "Add block" popover - a flat list of near-identical icon
 * buttons - with a modal showing each block as a screenshot card with its icon, name and
 * description, organised into named groups.
 *
 * This file is deliberately hand-written and dependency-free: silverstripe/admin's webpack
 * build exposes React, PropTypes, Reactstrap, classnames, Injector, Config, i18n and jQuery
 * onto globalThis, so a plain <script> can join the CMS's React tree with no build step.
 */
(function () {
  'use strict';

  /* global window, document */

  // Injector and Config are exposed as ES module namespaces, not as the values themselves.
  var Inj = (window.Injector && window.Injector.default) || window.Injector;
  var Cfg = (window.Config && window.Config.default) || window.Config;

  // These are plain objects, so they can be used directly.
  var React = window.React;
  var PropTypes = window.PropTypes;
  var Reactstrap = window.Reactstrap;
  var classNames = window.classnames;
  var i18n = window.i18n;

  var LOG_PREFIX = '[visual-block-picker]';
  var ELEMENTAL_SECTION = 'DNADesign\\Elemental\\Controllers\\ElementalAreaController';

  function warn(message, error) {
    if (window.console && window.console.error) {
      window.console.error(LOG_PREFIX, message, error || '');
    }
  }

  function translate(key, fallback) {
    // i18n._t() returns the fallback when there's no dictionary entry, so no lang files
    // are needed for the module to be usable in English.
    return i18n && i18n._t ? i18n._t(key, fallback) : fallback;
  }

  /**
   * The `visualBlockPicker` payload added to the client config by
   * ElementalAreaControllerExtension. Read once - it can't change within a page load.
   */
  var pickerConfig = null;

  function getPickerConfig() {
    if (pickerConfig) {
      return pickerConfig;
    }

    var section = null;

    try {
      section = Cfg && Cfg.getSection ? Cfg.getSection(ELEMENTAL_SECTION) : null;
    } catch (error) {
      warn('could not read the CMS client config', error);
    }

    var payload = (section && section.visualBlockPicker) || {};

    pickerConfig = {
      blocks: payload.blocks || {},
      groups: payload.groups || [],
      ungroupedTitle: payload.ungroupedTitle || '',
      showTitleIcons: payload.showTitleIcons === true
    };

    return pickerConfig;
  }

  /**
   * Join elemental's per-area `elementTypes` (already filtered to what's allowed on this
   * page) against our config payload, and bucket the result into ordered groups.
   *
   * Memoised against the elementTypes array, which is a stable reference for the life of
   * an ElementEditor - a page with 20 blocks mounts 22 pickers, and none of them should
   * repeat this work.
   */
  var groupCache = typeof window.WeakMap === 'function' ? new window.WeakMap() : null;

  function buildGroups(elementTypes) {
    if (groupCache && groupCache.has(elementTypes)) {
      return groupCache.get(elementTypes);
    }

    var config = getPickerConfig();
    var order = [];
    var buckets = {};

    function bucketFor(title) {
      var key = title || '';

      if (!buckets[key]) {
        buckets[key] = { title: title || '', blocks: [] };
        order.push(key);
      }

      return buckets[key];
    }

    // Seed the buckets in configured order so empty groups keep their place if a later
    // block lands in them.
    config.groups.forEach(function (title) {
      bucketFor(title);
    });

    elementTypes
      // ElementEditor maps allowed class names through .find(), which yields undefined for
      // a class that no longer exists, and ElementTypeRegistry registers a broken
      // BaseElement entry.
      .filter(function (elementType) {
        return elementType && !elementType.broken;
      })
      .forEach(function (elementType) {
        var entry = config.blocks[elementType.class] || {};
        var group = entry.group || config.ungroupedTitle;

        bucketFor(group).blocks.push({
          key: elementType.class,
          elementType: elementType,
          title: elementType.title || elementType.class,
          icon: elementType.icon,
          screenshot: entry.screenshot || null,
          description: entry.description || null,
          order: typeof entry.order === 'number' ? entry.order : null
        });
      });

    var groups = order
      .map(function (key) {
        return buckets[key];
      })
      .filter(function (group) {
        return group.blocks.length > 0;
      });

    // Cards follow the order the blocks are listed in under their group, so a group can lead
    // with the block editors reach for most. elementTypes arrives alphabetically, which is
    // the right fallback for anything the config doesn't place.
    groups.forEach(function (group) {
      group.blocks.sort(function (a, b) {
        if (a.order === null && b.order === null) {
          return a.title.localeCompare(b.title);
        }

        if (a.order === null) {
          return 1;
        }

        if (b.order === null) {
          return -1;
        }

        return a.order - b.order;
      });
    });

    if (groupCache) {
      groupCache.set(elementTypes, groups);
    }

    return groups;
  }

  /**
   * Case-insensitive substring match across name, description and group title.
   */
  function filterGroups(groups, term) {
    var needle = term.trim().toLowerCase();

    if (!needle) {
      return groups;
    }

    return groups
      .map(function (group) {
        var groupMatches = group.title.toLowerCase().indexOf(needle) !== -1;

        return {
          title: group.title,
          blocks: group.blocks.filter(function (block) {
            if (groupMatches) {
              return true;
            }

            var haystack = block.title + ' ' + (block.description || '');

            return haystack.toLowerCase().indexOf(needle) !== -1;
          })
        };
      })
      .filter(function (group) {
        return group.blocks.length > 0;
      });
  }

  function countBlocks(groups) {
    return groups.reduce(function (total, group) {
      return total + group.blocks.length;
    }, 0);
  }

  /**
   * Replicate the preview reload elemental's own popover does after adding a block -
   * without it the CMS preview pane goes stale after every add.
   */
  function reloadPreview() {
    try {
      var $ = window.jQuery;

      if (!$) {
        return;
      }

      var preview = $('.cms-preview');

      if (!preview.length || !preview.entwine) {
        return;
      }

      preview.entwine('ss.preview')._loadUrl(preview.find('iframe').attr('src'));
    } catch (error) {
      warn('could not reload the preview pane', error);
    }
  }

  function createPicker() {
    var h = React.createElement;
    var Modal = Reactstrap.Modal;
    var ModalHeader = Reactstrap.ModalHeader;
    var ModalBody = Reactstrap.ModalBody;

    function VisualBlockPicker(props) {
      var elementTypes = props.elementTypes;
      var isOpen = props.isOpen;
      var toggle = props.toggle;

      var searchState = React.useState('');
      var search = searchState[0];
      var setSearch = searchState[1];
      var searchRef = React.useRef(null);
      var reactId = React.useId();
      var titleId = 'visual-block-picker-title-' + reactId;
      var searchId = 'visual-block-picker-search-' + reactId;

      var handleToggle = React.useCallback(function () {
        // The popover we replace cleared its search on close; reactstrap won't.
        setSearch('');
        toggle();
      }, [toggle]);

      var handleAdd = React.useCallback(function (elementType) {
        return function (event) {
          event.preventDefault();

          var handleAddElementToArea = props.actions && props.actions.handleAddElementToArea;

          if (!handleAddElementToArea) {
            warn('the add-element mutation is unavailable - is the transform registered after "cms-element-adder"?');
            return;
          }

          Promise.resolve(handleAddElementToArea(elementType.class, props.insertAfterElement))
            .then(reloadPreview)
            .catch(function (error) {
              // Neither elemental's mutation nor its popover handles a rejection, which
              // otherwise leaves an unhandled rejection and a silently dead dialog.
              warn('could not add the block', error);
            });

          handleToggle();
        };
      }, [props.actions, props.insertAfterElement, handleToggle]);

      var groups = React.useMemo(function () {
        return buildGroups(elementTypes || []);
      }, [elementTypes]);

      var visibleGroups = React.useMemo(function () {
        return filterGroups(groups, search);
      }, [groups, search]);

      var showTitleIcons = getPickerConfig().showTitleIcons;

      // AddElementPopover is mounted unconditionally at every call site - once for the
      // toolbar button and once per hover bar - so a 20-block page has 22 live instances.
      // Rendering nothing while closed keeps them free.
      if (!isOpen) {
        return null;
      }

      var resultCount = countBlocks(visibleGroups);
      var noResults = resultCount === 0;

      var searchField = h(
        'div',
        { className: 'visual-block-picker__search' },
        h(
          'label',
          { className: 'visual-block-picker__search-label', htmlFor: searchId },
          translate('ElementAddElementPopover.SEARCH_BLOCKS', 'Search blocks')
        ),
        h('input', {
          id: searchId,
          type: 'search',
          className: 'form-control visual-block-picker__search-input',
          placeholder: translate('ElementAddElementPopover.SEARCH_BLOCKS', 'Search blocks'),
          value: search,
          autoComplete: 'off',
          ref: searchRef,
          onChange: function (event) {
            setSearch(event.target.value);
          }
        })
      );

      var liveRegion = h(
        'div',
        {
          className: 'visual-block-picker__status',
          role: 'status',
          'aria-live': 'polite'
        },
        search.trim()
          ? resultCount + ' ' + (resultCount === 1 ? 'block' : 'blocks') + ' found'
          : ''
      );

      function renderCard(block) {
        var label = block.description
          ? block.title + ': ' + block.description
          : block.title;

        var thumbnail = block.screenshot
          ? h(
            'span',
            { className: 'visual-block-picker__shot' },
            h('img', {
              className: 'visual-block-picker__image',
              src: block.screenshot,
              alt: '',
              loading: 'lazy'
            })
          )
          : h(
            'span',
            { className: 'visual-block-picker__shot visual-block-picker__shot--empty' },
            h('span', {
              className: classNames(block.icon, 'visual-block-picker__shot-icon'),
              'aria-hidden': 'true'
            })
          );

        return h(
          'button',
          {
            type: 'button',
            key: block.key,
            className: 'visual-block-picker__card',
            'aria-label': label,
            onClick: handleAdd(block.elementType)
          },
          thumbnail,
          h(
            'span',
            { className: 'visual-block-picker__meta' },
            h(
              'span',
              { className: 'visual-block-picker__name' },
              // Off unless BlockPickerConfigProvider.show_title_icons is enabled. The icon
              // is decorative either way - the accessible name comes from aria-label.
              showTitleIcons
                ? h('span', {
                  className: classNames(block.icon, 'visual-block-picker__icon'),
                  'aria-hidden': 'true'
                })
                : null,
              h('span', { className: 'visual-block-picker__title' }, block.title)
            ),
            block.description
              ? h(
                'span',
                { className: 'visual-block-picker__description' },
                block.description
              )
              : null
          )
        );
      }

      function renderGroup(group, index) {
        return h(
          'section',
          {
            className: 'visual-block-picker__group',
            key: group.title || 'ungrouped-' + index
          },
          group.title
            ? h(
              'h3',
              { className: 'visual-block-picker__group-title' },
              group.title
            )
            : null,
          h(
            'div',
            { className: 'visual-block-picker__grid' },
            group.blocks.map(renderCard)
          )
        );
      }

      return h(
        Modal,
        {
          isOpen: true,
          toggle: handleToggle,
          size: 'lg',
          // Whitelisted props only: the call sites pass `container`, `target` and
          // `placement` for Popper, and reactstrap's Modal also honours `container` -
          // spreading would portal a full-screen dialog into a 4px hover bar.
          className: 'visual-block-picker__dialog',
          modalClassName: 'visual-block-picker',
          labelledBy: titleId,
          keyboard: true,
          trapFocus: true,
          returnFocusAfterClose: true,
          autoFocus: false,
          onOpened: function () {
            if (searchRef.current) {
              searchRef.current.focus();
            }
          }
        },
        h(
          ModalHeader,
          { toggle: handleToggle, id: titleId },
          translate('ElementAddNewButton.ADD_BLOCK', 'Add block')
        ),
        h(
          ModalBody,
          null,
          searchField,
          liveRegion,
          noResults
            ? h(
              'p',
              { className: 'visual-block-picker__empty' },
              translate('PopoverOptionSet.NO_RESULTS', 'No results found')
            )
            : visibleGroups.map(renderGroup)
        )
      );
    }

    if (PropTypes) {
      VisualBlockPicker.propTypes = {
        elementTypes: PropTypes.array.isRequired,
        isOpen: PropTypes.bool.isRequired,
        toggle: PropTypes.func.isRequired,
        insertAfterElement: PropTypes.oneOfType([PropTypes.number, PropTypes.string]),
        actions: PropTypes.object
      };
    }

    VisualBlockPicker.displayName = 'VisualBlockPicker';

    return VisualBlockPicker;
  }

  function boot() {
    if (!React || !Reactstrap || !classNames || !Inj || !Cfg) {
      warn('the CMS bundle did not expose the globals this module needs; picker not installed');
      return;
    }

    var picker = createPicker();

    try {
      Inj.transform(
        'visual-block-picker',
        function (updater) {
          // The wrapped component is deliberately discarded - this replaces elemental's
          // popover rather than decorating it.
          updater.component('AddElementPopover', function () {
            return picker;
          }, 'VisualBlockPicker');
        },
        // Required: MiddlewareRegistry sorts topologically and compose() makes the first
        // entry outermost, so running after 'cms-element-adder' means elemental's Apollo
        // mutation HOC wraps ours and passes down actions.handleAddElementToArea.
        { after: 'cms-element-adder' }
      );
    } catch (error) {
      warn('could not register the Injector transform', error);
    }
  }

  // Injector.load() runs from window.onload plus an awaited fetch, so registering on
  // DOMContentLoaded is comfortably early enough. Never assign window.onload here - the
  // admin bundle owns it.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}());
