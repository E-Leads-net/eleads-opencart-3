(function() {
  var cfg = window.EleadsAdminConfig || {};

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    var input = document.createElement('input');
    input.value = text;
    document.body.appendChild(input);
    input.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(input);
    return Promise.resolve();
  }

  window.eleadsCopySitemap = function(id) {
    var input = document.getElementById(id);
    if (!input) return;
    copyText(input.value || '');
  };

  var copyButtons = document.querySelectorAll('.eleads-copy-btn');
  Array.prototype.forEach.call(copyButtons, function(btn) {
    btn.addEventListener('click', function() {
      var url = btn.getAttribute('data-url') || '';
      copyText(url);
    });
  });

  function initMainTabsFix() {
    if (!cfg.fixMainTabs) return;
    var mainTabRoot = document.querySelector('.eleads-tabs .panel-body > .tab-content');
    if (!mainTabRoot) return;

    var paneOrder = ['tab-export', 'tab-filter', 'tab-seo', 'tab-api', 'tab-update'];
    Array.prototype.forEach.call(paneOrder, function(id) {
      var pane = document.getElementById(id);
      if (!pane) return;
      if (pane.parentNode !== mainTabRoot) {
        mainTabRoot.appendChild(pane);
      }
    });

    var links = document.querySelectorAll('.eleads-tabs .panel-heading .nav-tabs > li > a[data-toggle="tab"]');
    if (!links.length) return;

    function showTab(targetId) {
      Array.prototype.forEach.call(mainTabRoot.children, function(pane) {
        if (!pane.classList || !pane.classList.contains('tab-pane')) return;
        var isTarget = pane.id === targetId;
        pane.classList.toggle('active', isTarget);
        pane.style.display = isTarget ? 'block' : 'none';
      });

      Array.prototype.forEach.call(links, function(link) {
        var li = link.parentElement;
        if (!li) return;
        li.classList.toggle('active', (link.getAttribute('href') || '').replace('#', '') === targetId);
      });
    }

    var initial = null;
    Array.prototype.forEach.call(links, function(link) {
      var href = link.getAttribute('href') || '';
      if (!initial && link.parentElement && link.parentElement.classList.contains('active')) {
        initial = href.replace('#', '');
      }

      link.addEventListener('click', function(e) {
        if (!href || href.charAt(0) !== '#') return;
        e.preventDefault();
        showTab(href.slice(1));
      });
    });

    showTab(initial || 'tab-export');
  }

  function initCategoryTree() {
    window.eleadsSelectAllCategories = function(checked) {
      var tree = document.getElementById('eleads-categories-tree');
      if (!tree) return;
      var boxes = tree.querySelectorAll('input[type="checkbox"]');
      Array.prototype.forEach.call(boxes, function(cb) {
        cb.checked = checked;
        cb.indeterminate = false;
        var label = cb.closest('.eleads-tree-label');
        if (label) label.classList.remove('is-indeterminate');
      });
    };

    var allBtn = document.getElementById('eleads-categories-all');
    var noneBtn = document.getElementById('eleads-categories-none');
    var tree = document.getElementById('eleads-categories-tree');
    if (!tree) return;

    function getAllCheckboxes() {
      return tree.querySelectorAll('input[type="checkbox"][name="module_eleads_categories[]"]');
    }

    function setLabelState(input) {
      var label = input.closest('.eleads-tree-label');
      if (!label) return;
      if (input.indeterminate) {
        label.classList.add('is-indeterminate');
      } else {
        label.classList.remove('is-indeterminate');
      }
    }

    function updateNodeState(li) {
      if (!li) return;
      var checkbox = li.querySelector('.eleads-tree-label input[type="checkbox"]');
      var childrenWrap = li.getElementsByClassName('eleads-tree-children')[0];
      if (!checkbox || !childrenWrap) return;
      var children = childrenWrap.querySelectorAll('input[type="checkbox"]');
      if (!children.length) return;

      var checkedCount = 0;
      var indeterminateCount = 0;
      Array.prototype.forEach.call(children, function(cb) {
        if (cb.indeterminate) indeterminateCount++;
        if (cb.checked) checkedCount++;
      });

      if (checkedCount === children.length) {
        checkbox.checked = true;
        checkbox.indeterminate = false;
      } else if (checkedCount === 0 && indeterminateCount === 0) {
        checkbox.checked = false;
        checkbox.indeterminate = false;
      } else {
        checkbox.checked = false;
        checkbox.indeterminate = true;
      }

      setLabelState(checkbox);
    }

    function updateParents(li) {
      var parent = li.parentElement;
      while (parent && parent !== tree) {
        if (parent.classList && parent.classList.contains('eleads-tree-item')) {
          updateNodeState(parent);
        }
        parent = parent.parentElement;
      }
    }

    function setChildren(li, checked) {
      var children = li.querySelectorAll('.eleads-tree-children input[type="checkbox"]');
      Array.prototype.forEach.call(children, function(cb) {
        cb.checked = checked;
        cb.indeterminate = false;
        setLabelState(cb);
      });
    }

    function initTreeState() {
      var items = tree.querySelectorAll('.eleads-tree-item');
      Array.prototype.forEach.call(items, function(li) {
        updateNodeState(li);
      });

      Array.prototype.forEach.call(items, function(li) {
        var childrenWrap = li.getElementsByClassName('eleads-tree-children')[0];
        if (!childrenWrap) return;
        var children = childrenWrap.querySelectorAll('input[type="checkbox"]');
        var open = false;
        Array.prototype.forEach.call(children, function(cb) {
          if (cb.checked || cb.indeterminate) open = true;
        });
        if (open) li.classList.add('is-open');
      });
    }

    tree.addEventListener('click', function(e) {
      var toggle = e.target.closest('.eleads-tree-toggle');
      if (toggle) {
        var li = toggle.closest('.eleads-tree-item');
        if (li) li.classList.toggle('is-open');
      }
    });

    tree.addEventListener('change', function(e) {
      var input = e.target;
      if (!input.matches('input[type="checkbox"][name="module_eleads_categories[]"]')) return;
      var li = input.closest('.eleads-tree-item');
      if (!li) return;
      input.indeterminate = false;
      setLabelState(input);
      setChildren(li, input.checked);
      updateParents(li);
    });

    if (allBtn) {
      allBtn.addEventListener('click', function() {
        Array.prototype.forEach.call(getAllCheckboxes(), function(cb) {
          cb.checked = true;
          cb.indeterminate = false;
          setLabelState(cb);
        });
        initTreeState();
      });
    }

    if (noneBtn) {
      noneBtn.addEventListener('click', function() {
        Array.prototype.forEach.call(getAllCheckboxes(), function(cb) {
          cb.checked = false;
          cb.indeterminate = false;
          setLabelState(cb);
        });
        initTreeState();
      });
    }

    initTreeState();
  }

  function initFilterToggles() {
    var attrToggle = document.getElementById('eleads-filter-attributes-toggle');
    var optToggle = document.getElementById('eleads-filter-options-toggle');
    var attrBlock = document.querySelector('.eleads-filter-attributes-block');
    var optBlock = document.querySelector('.eleads-filter-options-block');

    function applyToggle(toggle, block) {
      if (!toggle || !block) return;
      block.style.display = toggle.value === '1' ? '' : 'none';
    }

    function refresh() {
      applyToggle(attrToggle, attrBlock);
      applyToggle(optToggle, optBlock);
    }

    if (attrToggle) attrToggle.addEventListener('change', refresh);
    if (optToggle) optToggle.addEventListener('change', refresh);
    refresh();
  }

  function bindSelectAll(buttonId, checked) {
    var btn = document.getElementById(buttonId);
    if (!btn) return;
    btn.addEventListener('click', function() {
      var group = btn.closest('.form-group, .row');
      if (!group) return;
      var items = group.querySelectorAll('input[type="checkbox"]');
      Array.prototype.forEach.call(items, function(cb) {
        cb.checked = checked;
      });
    });
  }

  function initTemplateEditor() {
    var templateList = document.getElementById('eleads-filter-templates-list');
    var templateAddBtn = document.getElementById('eleads-filter-template-add');
    var templateExpandAllBtn = document.getElementById('eleads-template-expand-all');
    var templateCollapseAllBtn = document.getElementById('eleads-template-collapse-all');
    var templateHintText = document.getElementById('eleads-template-vars-help-text');
    var depthInput = document.querySelector('input[name="module_eleads_filter_max_index_depth"]');
    var templateNextIndex = templateList ? templateList.querySelectorAll('.eleads-template-card-item').length : 0;

    var templateCategories = cfg.templateCategories || [];
    var languages = cfg.languages || [];
    var labels = cfg.labels || {};
    var selectClass = cfg.selectClass || 'form-control';
    var useBsTabs = !!cfg.useBsTabs;
    var removeClass = cfg.removeClass || 'eleads-filter-template-remove';
    var removeBtnClass = cfg.removeBtnClass || 'btn btn-danger btn-sm';
    var toggleBtnClass = cfg.toggleBtnClass || 'btn btn-default btn-sm';
    var confirmRemoveText = cfg.confirmRemoveText || 'Remove this template?';

    function templateCategoryOptions(selectedId) {
      var html = '<option value="0"' + (selectedId === 0 ? ' selected' : '') + '>' + (labels.allCategories || 'All categories') + '</option>';
      for (var i = 0; i < templateCategories.length; i++) {
        var item = templateCategories[i];
        var cid = parseInt(item.category_id, 10) || 0;
        var selected = cid === selectedId ? ' selected' : '';
        html += '<option value="' + cid + '"' + selected + '>' + item.name + '</option>';
      }
      return html;
    }

    function templateDepthOptions(selected) {
      var maxDepth = parseInt(depthInput && depthInput.value ? depthInput.value : '1', 10);
      if (isNaN(maxDepth) || maxDepth < 0) maxDepth = 0;
      var html = '';
      for (var d = 0; d <= maxDepth; d++) {
        html += '<option value="' + d + '"' + (selected === d ? ' selected' : '') + '>' + d + '</option>';
      }
      return html;
    }

    function refreshTemplateTitles() {
      if (!templateList) return;
      var nums = templateList.querySelectorAll('.eleads-template-card-title-num');
      Array.prototype.forEach.call(nums, function(node, idx) {
        node.textContent = String(idx + 1);
      });
    }

    function updateTemplateMeta(card) {
      if (!card) return;
      var meta = card.querySelector('.eleads-template-card-meta');
      var cat = card.querySelector('select[name*="[category_id]"]');
      var dep = card.querySelector('select[name*="[depth]"]');
      if (!meta || !cat || !dep) return;
      var catText = cat.options[cat.selectedIndex] ? cat.options[cat.selectedIndex].text : (labels.allCategories || 'All categories');
      var depthText = dep.value || '0';
      meta.textContent = ' · ' + catText + ' · depth ' + depthText;
    }

    function bindTemplateMetaChange() {
      if (!templateList) return;
      var cards = templateList.querySelectorAll('.eleads-template-card-item');
      Array.prototype.forEach.call(cards, function(card) {
        updateTemplateMeta(card);
        var cat = card.querySelector('select[name*="[category_id]"]');
        var dep = card.querySelector('select[name*="[depth]"]');
        if (cat) cat.onchange = function() { updateTemplateMeta(card); };
        if (dep) dep.onchange = function() { updateTemplateMeta(card); };
      });
    }

    function bindTemplateToggle() {
      if (!templateList) return;
      var buttons = templateList.querySelectorAll('.eleads-template-toggle');
      Array.prototype.forEach.call(buttons, function(btn) {
        btn.onclick = function() {
          var card = btn.closest('.eleads-template-card-item');
          if (!card) return;
          card.classList.toggle('is-collapsed');
          btn.textContent = card.classList.contains('is-collapsed') ? '+' : '−';
        };
      });
    }

    function bindTemplateRemove() {
      if (!templateList) return;
      var buttons = templateList.querySelectorAll('.' + removeClass + ', .eleads-template-remove, .eleads-filter-template-remove');
      Array.prototype.forEach.call(buttons, function(btn) {
        btn.onclick = function() {
          if (!window.confirm(confirmRemoveText)) return;
          var card = btn.closest('.eleads-template-card-item');
          if (card) card.parentNode.removeChild(card);
          refreshTemplateTitles();
        };
      });
    }

    function setAllTemplateCardsCollapsed(collapse) {
      if (!templateList) return;
      var cards = templateList.querySelectorAll('.eleads-template-card-item');
      Array.prototype.forEach.call(cards, function(card) {
        if (collapse) {
          card.classList.add('is-collapsed');
        } else {
          card.classList.remove('is-collapsed');
        }
        var btn = card.querySelector('.eleads-template-toggle');
        if (btn) btn.textContent = collapse ? '+' : '−';
      });
    }

    function applyInitialTemplateCollapse() {
      if (!templateList) return;
      var cards = templateList.querySelectorAll('.eleads-template-card-item');
      Array.prototype.forEach.call(cards, function(card) {
        card.classList.add('is-collapsed');
        var btn = card.querySelector('.eleads-template-toggle');
        if (btn) btn.textContent = '+';
      });
    }

    function renderTemplateHint() {
      if (!templateHintText) return;
      var depth = parseInt(depthInput && depthInput.value ? depthInput.value : '1', 10);
      if (isNaN(depth) || depth < 1) depth = 1;
      var vars = ['{$category}', '{$category_h1}', '{$brand}', '{$sitename}'];
      for (var i = 1; i <= depth; i++) {
        if (i === 1) {
          vars.push('{$attribute_name}');
          vars.push('{$attributes_val}');
        } else {
          vars.push('{$attribute_name_' + i + '}');
          vars.push('{$attributes_val_' + i + '}');
        }
      }
      templateHintText.textContent = 'Variables: ' + vars.join(', ') + '.';
    }

    function langTabsHtml(index) {
      var html = '<ul class="nav nav-tabs eleads-lang-tabs">';
      for (var i = 0; i < languages.length; i++) {
        var lang = languages[i];
        if (useBsTabs) {
          html += '<li class="nav-item"><a class="nav-link' + (i === 0 ? ' active' : '') + '" href="#eleads-template-new-' + index + '-' + i + '" data-bs-toggle="tab">' + lang.code + '</a></li>';
        } else {
          html += '<li' + (i === 0 ? ' class="active"' : '') + '><a href="#eleads-template-new-' + index + '-' + i + '" data-toggle="tab">' + lang.code + '</a></li>';
        }
      }
      html += '</ul>';
      return html;
    }

    function langPanesHtml(index) {
      var html = '<div class="tab-content eleads-lang-content">';
      for (var i = 0; i < languages.length; i++) {
        var lang = languages[i];
        html += '<div class="tab-pane' + (i === 0 ? ' active' : '') + '" id="eleads-template-new-' + index + '-' + i + '">';
        html += '<div class="row">';
        html += '<div class="col-sm-6">';
        html += '<div class="eleads-template-field"><label>' + (labels.h1 || 'H1 Template') + '</label><input type="text" name="module_eleads_filter_templates[' + index + '][translations][' + lang.code + '][h1]" class="form-control" /></div>';
        html += '<div class="eleads-template-field"><label>' + (labels.metaTitle || 'Meta Title Template') + '</label><input type="text" name="module_eleads_filter_templates[' + index + '][translations][' + lang.code + '][meta_title]" class="form-control" /></div>';
        html += '</div>';
        html += '<div class="col-sm-6">';
        html += '<div class="eleads-template-field"><label>' + (labels.metaDescription || 'Meta Description Template') + '</label><textarea name="module_eleads_filter_templates[' + index + '][translations][' + lang.code + '][meta_description]" rows="3" class="form-control"></textarea></div>';
        html += '<div class="eleads-template-field"><label>' + (labels.metaKeywords || 'Meta Keywords Template') + '</label><textarea name="module_eleads_filter_templates[' + index + '][translations][' + lang.code + '][meta_keywords]" rows="3" class="form-control"></textarea></div>';
        html += '</div>';
        html += '</div>';
        html += '<div class="row"><div class="col-sm-12"><div class="eleads-template-field"><label>' + (labels.shortDescription || 'Short Description Template') + '</label><textarea name="module_eleads_filter_templates[' + index + '][translations][' + lang.code + '][short_description]" rows="4" class="form-control"></textarea></div></div></div>';
        html += '<div class="row"><div class="col-sm-12"><div class="eleads-template-field"><label>' + (labels.description || 'Description Template') + '</label><textarea name="module_eleads_filter_templates[' + index + '][translations][' + lang.code + '][description]" rows="5" class="form-control"></textarea></div></div></div>';
        html += '</div>';
      }
      html += '</div>';
      return html;
    }

    function addTemplateRow() {
      if (!templateList) return;
      var index = templateNextIndex++;
      var card = document.createElement('div');
      card.className = 'eleads-template-card-item';
      card.innerHTML =
        '<div class="eleads-template-card-head">' +
          '<div class="eleads-template-card-title">Template #<span class="eleads-template-card-title-num"></span><span class="eleads-template-card-meta"></span></div>' +
          '<div class="eleads-template-card-controls">' +
            '<button type="button" class="' + toggleBtnClass + ' eleads-template-toggle">−</button>' +
            '<button type="button" class="' + removeBtnClass + ' ' + removeClass + '">' + (labels.remove || 'Remove') + '</button>' +
          '</div>' +
        '</div>' +
        '<div class="eleads-template-card-body">' +
          '<div class="row">' +
            '<div class="col-sm-8">' +
              '<div class="eleads-template-field"><label>' + (labels.category || 'Category') + '</label><select name="module_eleads_filter_templates[' + index + '][category_id]" class="' + selectClass + '">' + templateCategoryOptions(0) + '</select></div>' +
            '</div>' +
            '<div class="col-sm-4">' +
              '<div class="eleads-template-field"><label>' + (labels.depth || 'Template Depth') + '</label><select name="module_eleads_filter_templates[' + index + '][depth]" class="' + selectClass + ' eleads-template-depth">' + templateDepthOptions(0) + '</select></div>' +
            '</div>' +
          '</div>' +
          langTabsHtml(index) +
          langPanesHtml(index) +
        '</div>';

      templateList.appendChild(card);
      bindTemplateRemove();
      bindTemplateToggle();
      refreshTemplateTitles();
      bindTemplateMetaChange();
    }

    if (templateAddBtn) {
      templateAddBtn.addEventListener('click', addTemplateRow);
    }

    bindTemplateRemove();
    bindTemplateToggle();
    refreshTemplateTitles();
    bindTemplateMetaChange();
    applyInitialTemplateCollapse();

    if (templateExpandAllBtn) templateExpandAllBtn.addEventListener('click', function() { setAllTemplateCardsCollapsed(false); });
    if (templateCollapseAllBtn) templateCollapseAllBtn.addEventListener('click', function() { setAllTemplateCardsCollapsed(true); });

    function refreshDepthSelectors() {
      if (!templateList) return;
      var maxDepth = parseInt(depthInput && depthInput.value ? depthInput.value : '1', 10);
      if (isNaN(maxDepth) || maxDepth < 0) maxDepth = 0;
      var selects = templateList.querySelectorAll('.eleads-template-depth');
      Array.prototype.forEach.call(selects, function(sel) {
        var current = parseInt(sel.value || '0', 10);
        if (isNaN(current) || current < 0) current = 0;
        if (current > maxDepth) current = maxDepth;
        sel.innerHTML = templateDepthOptions(current);
      });
      bindTemplateMetaChange();
    }

    if (depthInput) {
      depthInput.addEventListener('input', renderTemplateHint);
      depthInput.addEventListener('change', renderTemplateHint);
      depthInput.addEventListener('input', refreshDepthSelectors);
      depthInput.addEventListener('change', refreshDepthSelectors);
    }

    renderTemplateHint();
    refreshDepthSelectors();
  }

  initMainTabsFix();
  initCategoryTree();
  initFilterToggles();
  bindSelectAll('eleads-attributes-all', true);
  bindSelectAll('eleads-attributes-none', false);
  bindSelectAll('eleads-options-all', true);
  bindSelectAll('eleads-options-none', false);
  bindSelectAll('eleads-whitelist-attributes-all', true);
  bindSelectAll('eleads-whitelist-attributes-none', false);
  initTemplateEditor();
})();
