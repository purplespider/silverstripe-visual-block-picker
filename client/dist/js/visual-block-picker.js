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

  /**
   * Carries AddElementPopover's `elementTypes` down to the option set it renders, which
   * only receives the derived `buttons` and so can't see block classes or icons itself.
   */
  var ElementTypesContext = React ? React.createContext(null) : null;

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
          // Elemental keys each add button by the type name, so this finds the button.
          name: elementType.name,
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

  function createPicker() {
    var h = React.createElement;
    var Modal = Reactstrap.Modal;
    var ModalHeader = Reactstrap.ModalHeader;
    var ModalBody = Reactstrap.ModalBody;

    function VisualBlockPicker(props) {
      var elementTypes = props.elementTypes;
      var buttons = props.buttons;
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

      var buttonsByName = React.useMemo(function () {
        var map = {};

        (buttons || []).forEach(function (button) {
          map[button.key] = button;
        });

        return map;
      }, [buttons]);

      var handleAdd = React.useCallback(function (block) {
        return function (event) {
          var button = buttonsByName[block.name];

          if (!button || !button.onClick) {
            event.preventDefault();
            warn('elemental supplied no add button for ' + block.key);
            return;
          }

          // Elemental's own handler does the whole add - the request, the area refresh,
          // the preview reload and any error toast - and closes the popover via toggle().
          // That's the same toggle we were given, so clearing search is all that's left.
          setSearch('');
          button.onClick(event);
        };
      }, [buttonsByName]);

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

      // Sits inside the search wrapper so it can be overlaid on the field's right-hand end,
      // out of the flow - the results below don't shift as it appears and disappears.
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
        }),
        liveRegion
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
            onClick: handleAdd(block)
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
          // Whitelisted props only: elemental passes `container`, `target` and
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
        buttons: PropTypes.array.isRequired,
        isOpen: PropTypes.bool.isRequired,
        toggle: PropTypes.func.isRequired
      };
    }

    VisualBlockPicker.displayName = 'VisualBlockPicker';

    return VisualBlockPicker;
  }

  /**
   * Stands in for the PopoverOptionSet that elemental's AddElementPopover renders.
   *
   * Taking over here rather than replacing AddElementPopover means elemental keeps doing the
   * adding. How it adds a block has changed between majors - an Apollo mutation HOC
   * ('cms-element-adder') in 5, a REST call inline in the popover in 6 - but both build the
   * same `buttons` with a working onClick and hand them to this component.
   */
  function createOptionSet(picker) {
    var h = React.createElement;

    return function (PopoverOptionSet) {
      function VisualBlockPickerOptionSet(props) {
        var elementTypes = React.useContext(ElementTypesContext);

        // Something other than AddElementPopover rendered PopoverOptionSet in the
        // ElementEditor context - leave that one alone.
        if (!elementTypes) {
          return h(PopoverOptionSet, props);
        }

        return h(picker, Object.assign({}, props, { elementTypes: elementTypes }));
      }

      VisualBlockPickerOptionSet.displayName = 'VisualBlockPickerOptionSet';

      return VisualBlockPickerOptionSet;
    };
  }

  function provideElementTypes(AddElementPopover) {
    var h = React.createElement;

    function AddElementPopoverWithTypes(props) {
      return h(
        ElementTypesContext.Provider,
        { value: props.elementTypes || null },
        h(AddElementPopover, props)
      );
    }

    AddElementPopoverWithTypes.displayName = 'AddElementPopoverWithTypes';

    return AddElementPopoverWithTypes;
  }

  function boot() {
    if (!React || !React.createContext || !Reactstrap || !classNames || !Inj || !Cfg) {
      warn('the CMS bundle did not expose the globals this module needs; picker not installed');
      return;
    }

    var picker = createPicker();

    try {
      Inj.transform('visual-block-picker', function (updater) {
        updater.component('AddElementPopover', provideElementTypes, 'AddElementPopoverWithTypes');

        // AddElementPopover injects PopoverOptionSet with the 'ElementEditor' context, so
        // this swaps it there without touching any other option set in the CMS.
        updater.component('PopoverOptionSet.ElementEditor', createOptionSet(picker), 'VisualBlockPicker');
      });
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
