/**
 * This JS file is part of the MOOC Addin (https://en.wikiversity.org/wiki/Mooc-Module).
 * 
 * @author Sebastian Schlicht (https://en.wikiversity.org/wiki/User:Sebschlicht)
 * @author René Pickhardt (http://www.rene-pickhardt.de/)
 * 
 * The JS code makes extensive usage of jQuery and enables main UI features such as
 *   1.) sticky navigation
 *   2.) hover effects
 *   3.) modal boxes for in-place edits
 *   4.) insertion of on-page discussion
 *
 * The MediaWiki API (https://www.mediawiki.org/wiki/API:Main_page) is used to make edits/post and retrieve the MOOC index.
 * For some requests and in order to use messages the MediaWiki JS API (https://doc.wikimedia.org/mediawiki-core/master/js/#!/api/mw.Api) is used in addition.
 * 
 * Note:
 *
 * Names were choosen not with having collision in mind.
 * Further refactoring should prefix all functions and global variables with "AddinMooc_". 
 *
 * The style of some elements depends on the application state, such as scroll bar position, and thus require the usage of JavaScript.
 * Thus some CSS directives are not included in the CSS file but get set dynamically.
 * In future this could/should be strictly separated into
 *   1.) CSS directives limited to a state class and
 *   2.) JavaScript code en-/disabling these state classes
 */
/* necessary to avoid interpretation of special character sequences such as signations */
// <nowiki>

var AddinMooc_VERSION = '0.2';

/**
 * Global configuration object to work with constants and messages.
 */
var AddinMooc_CONFIG = {
  LOADED: 0,
  MSG_PREFIX: 'AddinMooc-',
  store: {},
  get: function(key) {
    return this.store[key];
  },
  set: function(key, value) {
    this.store[key] = value;
  },
  log: function(logLevel, key, params) {
    if (arguments.length > 0) {
      var minLevel = arguments[0];
      var crrLevel = this.get('LOG_LEVEL');
      if (crrLevel != -1 && minLevel >= crrLevel) {
        var msgParams = [];
        for (var i = 1; i < arguments.length; ++i) {
          msgParams[i - 1] = arguments[i];
        }
        console.log(this.message.apply(this, msgParams));
      }
    }
  },
  message: function(key, params) {
    var msgParams = [];
    msgParams[0] = this.MSG_PREFIX + key;
    for (var i = 1; i < arguments.length; ++i) {
      msgParams[i] = arguments[i];
    }
    // call message constructor with additional function parameters in separate object
    return mw.message.apply(mw.message, msgParams).text();
  },
  setMessage: function(key, message) {
    mw.messages.set(this.MSG_PREFIX + key, message);
  }
};

/*####################
  # ENTRY POINT
  # system initialization
  ####################*/

// load config
importScript('MediaWiki:Common.js/addin-mooc-config.js');

// load messages
importScript('MediaWiki:Common.js/addin-mooc-localization.js');

//DEBUG
var execOnReady = function(callback) {
  if (AddinMooc_CONFIG.LOADED < 2) {
    setTimeout(function() {
      execOnReady(callback);
    }, 200);
  } else {
    callback();
  }
};

// declare global variables
var PARAMETER_KEY = {
  FURTHER_READING: 'furtherReading',
  LEARNING_GOALS: 'learningGoals',
  NUM_THREADS: 'numThreads',
  NUM_THREADS_OPEN: 'numThreadsOpen',
  VIDEO: 'video'
};
var AddinMooc_root;
var _base;
var _fullPath;
var _indexSection;
var _indexTitle;
var _index;
var nItemNav;
var sidebar;
var discussion;

// register onHashChange to expand sections browsed via anchors
if ("onhashchange" in window) {
  window.onhashchange = function() {
    hashChanged(window.location.hash);
  };
} else {
  var prevHash = window.location.hash;
  window.setInterval(function() {
    if (window.location.hash != prevHash) {
      prevHash = window.location.hash;
      hashChanged(prevHash);
    }
  }, 100);
}

execOnReady(function() {

// load jQuery
$(document).ready(function() {
  // setup user agent for API requests
  $.ajaxSetup({
    beforeSend: function(request) {
      request.setRequestHeader("User-Agent", AddinMooc_CONFIG.get('USER_AGENT_NAME') + '/' + AddinMooc_VERSION + ' (' + AddinMooc_CONFIG.get('USER_AGENT_URL') + '; ' + AddinMooc_CONFIG.get('USER_AGENT_EMAIL') + ')');
    }
  });
	
  // connect to UI via DOM tree
  AddinMooc_root = $('#addin-mooc');
  _base = $('#baseUrl').text();
  _fullPath = $('#path').text();
  _indexSection = $('#section').text();
  _indexTitle = $('#indexUrl').text();
  nItemNav = $('#item-navigation');
  sidebar = $('#navigation');
  
  if (AddinMooc_root.length === 0) {// not a MOOC page
    AddinMooc_CONFIG.log(0, 'LOG_PAGE_NOMOOC');
    return;
  }
  
  // initialize
  if (_fullPath === '') {// path of root item equals base
    _fullPath = _base;
  }
  AddinMooc_CONFIG.log(0, 'LOG_INDEX', _indexTitle, _base);
  _index = MoocIndex(_indexTitle, _base);
  if (_indexSection !== '') {// use current item if not root
    _index.useItem(_indexSection, _fullPath);
  }
  discussion = Discussion();
  
  // load item navigation
  nItemNav.children('.section-link-wrapper').click(function() {
    var nSectionLink = $(this);
    var nSection = $('#' + nSectionLink.attr('id').substring(13));
    if (nSection.hasClass('collapsed')) {
      expand(nSection);
    }
    scrollIntoView(nSection);
    return false;
  });
  nItemNav.toggle(true);

  // collapse script section
  collapse($('#script'));
  // expand active section
  hashChanged(window.location.hash);
  //fix section for duration of section expansion animation
  if (window.location.hash !== '') {//Q: safe to delete?
    var section = $(window.location.hash);
    fixView(section, 600);
  }
  
  /**
   * prepares headers
   * * expand/collapse section when header clicked
   * * fade in/out action buttons when entering section
   */
  $('.section > .header').each(function() {
    var nHeader = $(this);
    var nSection = nHeader.parent();
    nHeader.click(function(e) {
      var target = $(e.target);
      if (!target.is('.header', ':header') && target.parents(':header').length === 0) {// filter clicks at action buttons
        return true;
      }
      if (nSection.hasClass('collapsed')) {
        expand(nSection);
      } else {
        collapse(nSection);
      }
      return false;
    });
    var nActions = nHeader.find('.actions');
    var nActionButtons = nActions.children().not('.modal-box');
    nActionButtons.each(function() {// remove image links from action buttons
      var btn = $(this);
      var img = btn.find('img');
      btn.append(img).find('a').remove();
    });
    nSection.mouseenter(function() {
      nActionButtons.stop().fadeIn();
    });
    nSection.mouseleave(function() {
      nActionButtons.stop().fadeOut();
    });
  });
  
  /**
   * loads overlays
   * * fade in/out overlays when mouse entering/leaving parent
   */
  $('.overlay').parent().mouseenter(function() {
    var overlay = $(this).children('.overlay');
    if (overlay.css('display') === 'none') {
      overlay.stop().toggle('fast');
    }
  });
  $('.overlay').parent().mouseleave(function() {
    var overlay = $(this).children('.overlay');
    if (overlay.css('display') !== 'none') {
      overlay.stop().toggle('fast');
    }
  });
  
  /**
   * prepares child units
   * * register click event for unit
   * * register click event for discussion statistic
   * * fade in/out discussion statistic when mouse entering/leaving
   * * get video URL
   */
  var unitButtons = [];
  var videoTitles = [];
  $('.children .unit').not('#addUnit').not('#addLesson').not('#addMooc').each(function() {
    var nChild = $(this);
    var nIconBar = nChild.find('.icon-bar');
    var nIconBarItems = nIconBar.find('li').not('.disabled');
    var iconBarOpacity = nIconBarItems.css('opacity');
    var nDownloadButton = nIconBar.find('li').eq(1);
    if (nDownloadButton.length > 0 && !nDownloadButton.hasClass('disabled')) {
      unitButtons.push(nDownloadButton);
      videoTitles.push(nDownloadButton.children('a').attr('href').substring(6).replace(/_/g, ' '));
    }
    var nDisStatisticWrapper = nChild.find('.discussion-statistic-wrapper');
    var nDisStat = nDisStatisticWrapper.children('.discussion-statistic');
    var url = nChild.children('.content').children('.title').find('a').attr('href');
    nChild.mouseenter(function() {// show disussion stats when mouse enters child
      nDisStatisticWrapper.stop().fadeIn();
      nIconBarItems.css('opacity', '1');
    });
    nChild.mouseleave(function() {// hide discussion stats when mouse leaves child
      nDisStatisticWrapper.stop().fadeOut();
      nIconBarItems.css('opacity', iconBarOpacity);
    });
    nChild.click(function() {// item click (may target underlying elements)
      window.location = url;
      return true;
    });
    nDisStat.click(function() {// discussion statistic click
      window.location = url + '#discussion';
      return false;
    });
	});
  getVideoUrls(videoTitles, function(videoUrls) {
		for (var i = 0; i < videoTitles.length; ++i) {
			var url = videoUrls[videoTitles[i]];
			if (url) {
				unitButtons[i].children('a').attr('href', url);
			}
		}
	});
  
  // make edit text links working in empty sections
  $('.empty-section .edit-text').click(function() {
    var section = $(this).parents('.section');
    if (section.length == 1) {
      section.children('.header').find('.btn-edit').click();
    }
  });
  
  /**
   * makes item navigation sticky at upper screen border (#1)
   */
  if (nItemNav.length > 0) {
    var itemNavTop = nItemNav.offset().top;//TODO ensure offset().top work correctly
    $(window).scroll(function() {
      var y = $(window).scrollTop();
      var isFixed = nItemNav.hasClass('fixed');
      if (y >= itemNavTop) {
        if (!isFixed) {
          nItemNav.after($('<div>', {
            'id': 'qn-replace',
            'height': nItemNav.height()
          }));
          nItemNav.css('width', nItemNav.outerWidth());
          nItemNav.css('position', 'fixed');
          nItemNav.css('top', 0);
          nItemNav.addClass('fixed');
        }
      } else {
        if (isFixed) {
          nItemNav.css('width', '100%');
          nItemNav.css('position', 'relative');
          nItemNav.css('top', null);
          nItemNav.removeClass('fixed');
          nItemNav.next().remove();
        }
      }
    });
  }
  
  /**
   * makes navigation bar sticky at upper right screen border
   */
  if (sidebar.length > 0) {
    var header = sidebar.find('.header-wrapper');
    var sidebarTop = sidebar.offset().top;
    var marginBottom = 10;
    function fixNavBarHeader(header) {
      header.css('width', header.outerWidth());
      header.css('position', 'fixed');
      header.addClass('fixed');
    }
    function resetNavBarHeader(header) {
      header.removeClass('fixed');
      header.css('position', 'absolute');
      header.css('width', '100%');
    }
    function fixNavBar(navBar) {
      navBar.removeClass('trailing');
      navBar.css('bottom', 'auto');
      navBar.css('position', 'fixed');
      navBar.css('top', 0);
      navBar.addClass('fixed');
    }
    function preventNavBarScrolling(navBar, marginBottom) {
      navBar.removeClass('fixed');
      navBar.css('top', 'auto');
      navBar.css('position', 'fixed');
      navBar.css('bottom', marginBottom);
      navBar.addClass('trailing');
    }
    function resetNavBar(navBar) {
      navBar.removeClass('fixed');
      navBar.removeClass('trailing');
      navBar.css('position', 'relative');
    }
    $(window).scroll(function() {
      var maxY = sidebarTop + sidebar.outerHeight();
      var h = Math.max(document.documentElement.clientHeight, window.innerHeight || 0);
      var y = $(this).scrollTop(); 
      var navBarScrolling = !sidebar.hasClass('trailing');
      var navBarFixed = sidebar.hasClass('fixed');
      var headerFixed = header.hasClass('fixed');
      if (y >= sidebarTop) {// navigation bar reached top screen border
        if (sidebar.outerHeight() <= h - marginBottom) {// fix navigation bar that fits in window
          if (!navBarFixed) {
            fixNavBar(sidebar);
          }
        } else {// navigation bar too large
          if (!headerFixed) { // fix navigation header
            fixNavBarHeader(header);
          }
          if (y + h >= maxY + marginBottom) {// disable scrolling when navigation bottom reached
            if (navBarScrolling) {
              preventNavBarScrolling(sidebar, marginBottom);
            }
          } else {// enable scrolling if still content available
            if (!navBarScrolling) {
              resetNavBar(sidebar);
            }
          }
        }
      } else {// navigation bar is back at its place
        if (headerFixed) {
          resetNavBarHeader(header);
        }
        if (navBarFixed) {
          resetNavBar(sidebar);
        }
      }
    });
  }
  
  /**
   * makes section headers sticky at upper screen border (#2)
   */
  if (AddinMooc_root.length > 0) {
    function setActiveSection(section) {
      var activeSection = $('.section').filter('.active');
      if (activeSection.length > 0) {
        setSectionActive(activeSection, false);
      }
      if (section != null) {
        setSectionActive(section, true);
      } else {//TODO replace with cross browser compatible solution (problems in e.g. Chrome 36.0.1985.125)
        //history.replaceState(null, null, window.location.pathname);
      }
    }
    function setSectionActive(section, isActive) {
      var sectionId = section.attr('id');
      var sectionAnchor = nItemNav.find('#section-link-' + sectionId);
      if (isActive) {//TODO replace with cross browser compatible solution (problems in e.g. Chrome 36.0.1985.125)
        sectionAnchor.addClass('active');
        section.addClass('active');
        //history.replaceState({}, '', '#' + sectionId);
      } else {
        sectionAnchor.removeClass('active');
        section.removeClass('active');
        resetHeader(section.children('.header'));
      }
    }
    function fixHeader(header, top) {
      header.css('position', 'fixed');
      header.css('top', top);
      header.css('width', header.parent().width());
      header.removeClass('trailing');
      header.addClass('fixed');
    }
    function resetHeader(header) {
      if (header.hasClass('fixed')) {
        header.css('position', 'absolute');
        header.css('width', '100%');
        header.removeClass('fixed');
      }
      header.css('top', 0);
      header.removeClass('trailing');
    }
    function trailHeader(header) {
      if (header.hasClass('fixed')) {
        header.css('position', 'absolute');
        header.css('width', '100%');
        header.removeClass('fixed');
      }
      header.css('top', header.parent().height() - header.outerHeight());
      header.addClass('trailing');
    }
    $(window).scroll(function() {
      var y = $(window).scrollTop();
      var h = Math.max(document.documentElement.clientHeight, window.innerHeight || 0);
      var marginTop = 0;
      if (nItemNav.hasClass('fixed')) {// correct scroll position
        marginTop = nItemNav.outerHeight() - 1;
        y += marginTop;
      }
      var activeSection = null;
      $('.section').each(function() {
        var section = $(this);
        var sectionHeader = section.children('.header');
        var sectionTop = section.offset().top;
        var sectionHeight = section.height();
        var isActive = section.hasClass('active');
        var isFixed = sectionHeader.hasClass('fixed');

        if (y >= sectionTop && y <= sectionTop + sectionHeight) {// active section
          if (!isActive) {
            setActiveSection(section);
          }
          activeSection = section;
          if (y <= sectionTop + sectionHeight - sectionHeader.outerHeight()) {// header can be fixed
            if (!isFixed) {
              fixHeader(sectionHeader, marginTop);
            }
          } else {// header reached section bottom
            if (!sectionHeader.hasClass('trailing')) {
              trailHeader(sectionHeader);
            }
          }
        } else {
          if (isActive) {
            resetHeader(sectionHeader);
          }
        }
      });
      if (activeSection == null) {
        setActiveSection(null);
      }
    });
  }
  
  // inject modal boxes
  prepareModalBoxes();
  // fill modal boxes when item loaded
  _index.retrieveItem(function(item) {
    fillModalBoxes(item);
  });
  
  // make edit buttons clickable
  var showModalBox = function() {
    var btn = $(this);
    var modal = btn.next('.modal-box');
    if (modal.length == 0) {
      modal = btn.next().next('.modal-box');
    }
    //TODO what happens if no header but addLesson aso?
    var header = modal.parent().parent();
    nItemNav.css('z-index', 1);
    header.css('z-index', 2);
    // show modal box with focus on edit field
    var editField = modal.find('fieldset').children('textarea');
    modal.toggle('fast', function() {
      editField.focus();
    });
    return false;
  };
  $('.btn-edit').each(function() {
    var btn = $(this);
    btn.click(showModalBox);
  });
  
  // make add unit div clickable
  var divAddUnit = $('#addUnit');
  var imgAddUnit = divAddUnit.find('img');
  divAddUnit.find('span').append(imgAddUnit).children('a.image').remove();
  divAddUnit.click(showModalBox);
  divAddUnit.show();
  // make add lesson clickable
  var divAddLesson = $('#addLesson');
  var imgAddLesson = divAddLesson.find('img');
  divAddLesson.find('span').append(imgAddLesson).children('a.image').remove();
  divAddLesson.click(showModalBox);
  divAddLesson.show();
  // make add MOOC clickable
  var divAddMooc = $('#addMooc');
  var imgAddMooc = divAddMooc.find('img');
  divAddMooc.find('span').append(imgAddMooc).children('a').remove();
  divAddMooc.click(showModalBox);
  
  // let redlinks create invoke pages
  var invokeItem = Item(Header(0, null, null, null), _index);
  $('#navigation a.new').click(function() {
    var link = $(this);
    var itemUrl = link.attr('href').replace(/_/g, ' ');
    itemUrl = itemUrl.substring(0, itemUrl.length - 22);
    var itemTitle = itemUrl.substring(19);
    createPage(itemTitle, invokeItem.getInvokeCode(), 'invoke page for MOOC item created', function() {// browse to created page
      window.location.href = itemUrl;
    });
    return false;
  });
  
  // inject discussion UI if not MOOC root
  if (_fullPath != _base) {
    var talkPageTitle = getTalkPageTitle(_fullPath);
    var scriptTalkPage = talkPageTitle + '/script';
    var quizTalkPage = talkPageTitle + '/quiz';
    renderThreads([
      talkPageTitle, scriptTalkPage, quizTalkPage
    ]);
  }
});

//DEBUG END
});

/*####################
  # UI UTILITIES
  # helper functions to change the user interface
  ####################*/

/**
 * Handler for onHashChange event. Expands the section browsed to via anchor.
 * @param {String} anchor ("hash") value
 */
function hashChanged(hash) {
  if (hash.length > 0) {
    var section = $(hash);
    if (section.hasClass('collapsed')) {
      expand(section);
    }
  }
}

/**
 * Reloads the current page.
 * @param {String} (optional) page anchor to be set
 */
function reloadPage(anchor) {
  if (typeof anchor === 'undefined') {
    document.location.search = document.location.search + '&action=purge';
  } else {
    window.location.href = document.URL.replace(/#.*$/, '') + '?action=purge' + anchor;
  }
}
 
/**
 * Displays a notification message to the user.
 * Uses mw.Message to generate messages.
 * @param {String} message key
 * @param {Array} message parameters
 */
function notifyUser(msgKey, msgParams) {/* Q: do we really need this? */
	var msgValue = mw.msg(msgKey, msgParams);
	alert(msgValue);
}


/**
 * Collapses a section to a fixed height that can be configured. The section is then expandable again.
 * Only applies to non-collapsed sections that are larger than the collapsed UI would be.
 * @param {jQuery} section node to be collapsed
 */
function collapse(section) {//TODO: rename to collapseSection; use config height to check if to collapse
  var content = section.children('.content');
  if (section.hasClass('collapsed') || content.height() <= 80) {
    return;
  }
  section.addClass('collapsed');
  var btnReadMore = $('<div>', {
    'class': 'btn-expand'
  }).html(AddinMooc_CONFIG.message('UI_SECTION_BTN_EXPAND'));
  btnReadMore.click(function() {
    expand(section);
    return false;
  });// expandable via button click
  section.append(btnReadMore);
  section.on('click', function() {
    expand(section);
    return true;
  });// expandable via section click (may target underlying elements)
  section.focusin(function() {
    expand(section);
    return true;
  });// expandable via focusing any child element (may target underlying elements)
  var btnHeight = btnReadMore.css('height');
  btnReadMore.css('height', '0');
  btnReadMore.stop().animate({
    'height': btnHeight
  }, function() {
    btnReadMore.css('height', null);
  });
  content.stop().animate({
    'height': AddinMooc_CONFIG.get('UI_SECTION_COLLAPSED_HEIGHT') + 'px'
  }, 'slow');
}

/**
 * Expands a section to its full height making it collapsible again.
 * @param {jQuery} section node to be expanded
 */
function expand(section) {//TODO: rename to expandSection
  section.removeClass('collapsed');
  var content = section.children('.content');
  var crrHeight = content.css('height');
  var targetHeight = content.css('height', 'auto').height();
  section.children('.btn-expand').stop().animate({
    'height': '0'
  }, 'slow', function() {
    $(this).remove();
  });
  section.off('click');
  content.css('height', crrHeight);
  content.stop().animate({
    'height': targetHeight
  }, 'slow', function() {
    content.css('height', 'auto');
  });
}

/**
 * Collapses a thread to match a fixed number of characters that can be configured. The thread is then expandable again.
 * @param {jQuery} thread node to be collapsed
 * @param {Object} thread displayed by the node
 */
function collapseThread(nThread, thread) {
  var content = nThread.children('.content');
  var nMessage = content.children('.message');
  var nMessageText = nMessage.children('.text');
  var nReplies = content.children('.replies');
  if (nThread.hasClass('collapsed') || (nMessageText.text().length < 100 && nReplies.children().length < 2 && content.height() <= 120)) {
    return;
  }
  var collapsedText = cutThreadContent(thread, AddinMooc_CONFIG.get('UI_THREAD_COLLAPSED_NUMCHARACTERS'));
  nMessageText.html(collapsedText);
  var targetHeight = nMessage.outerHeight() + 5;
  nMessageText.html(thread.htmlContent);
  nThread.addClass('collapsed');
  var btnReadMore = $('<div>', {
    'class': 'btn-expand'
  }).html(AddinMooc_CONFIG.message('UI_THREAD_BTN_EXPAND'));
  btnReadMore.click(function() {
    expandThread(nThread, thread);
    return false;
  });// expandable via button click
  nThread.append(btnReadMore);
  nThread.on('click', function() {
    expandThread(nThread, thread);
    return true;
  });// expandable via thread click (may target underlying elements)
  nThread.focusin(function() {
    expandThread(nThread, thread);
    return true;
  });// expandable via focusing any child element (may target underlying elements)
  var btnHeight = btnReadMore.css('height');
  btnReadMore.css('height', '0');
  btnReadMore.stop().animate({
    'height': btnHeight
  }, function() {
    btnReadMore.css('height', null);
  });
  content.stop().animate({
    'height': targetHeight + 'px'
  }, 'slow', function() {
    nMessageText.html(collapsedText);
  });
}

/**
 * Expands a thread to its full height making it collapsible again.
 * @param {jQuery} thread node to be expanded
 * @param {Object} thread displayed by the node
 */
function expandThread(nThread, thread) {
  nThread.removeClass('collapsed');
  var content = nThread.children('.content');
  var nMessageText = content.children('.message').children('.text');
  nMessageText.html(thread.htmlContent);
  var crrHeight = content.css('height');
  var targetHeight = content.css('height', 'auto').height();
  nThread.children('.btn-expand').stop().animate({
    'height': '0'
  }, 'slow', function() {
    $(this).remove();
  });
  nThread.off('click');
  content.css('height', crrHeight);
  content.stop().animate({
    'height': targetHeight
  }, 'slow', function() {
    content.css('height', 'auto');
  });
}

/**
 * Fixes a view at the upper screen border via scroll lock.
 * @param {jQuery} element to be fixed
 * @param {int} duration of the scroll lock
 */
function fixView(element, duration) {//Q: do we need this anymore?
  if (duration > 0) {
    element.css('background-color', '#FFF');
    var width = element.width();
    element.css('position', 'fixed');
    element.css('width', width);
    element.css('top', '0');
    var zIndex = element.css('z-index');
    element.css('z-index', 100);
    setTimeout(function() {
      element.css('position', 'relative');
      element.css('top', null);
      element.css('z-index', zIndex);
      window.scroll(0, element.offset().top);
      $(window).scroll();// fire jQuery event
    }, duration);
  }
}

/**
 * Scrolls an element into the user's view.
 * The final scroll position can handle movement of the element, the animation can not.
 * @param {jQuery} element to scroll into view
 * @param {String} 'top'/'bottom' if the element should be aligned at the upper/lower screen border. Defaults to 'top'.
 * @param {int} (optional) duration of the scroll animation; defaults to 1000ms
 */
function scrollIntoView(element, align, duration) {
  if (typeof duration === 'undefined') {
    duration = 1000;
  }
  var Alignment = {
    'TOP': 1,
    'BOTTOM': 2
  };
  if (align === 'bottom') {
    align = Alignment.BOTTOM;
  } else {
    align = Alignment.TOP;
  }
  var targetTop;
  var adjustAnimation = function(now, fx) {
    var h = Math.max(document.documentElement.clientHeight, window.innerHeight || 0);
    var y = $(window).scrollTop();
    var crrTop = element.offset().top;
    if (align === Alignment.BOTTOM) {
      crrTop += element.height() - h;
      //TODO currently just if scrolled FAR enough
      if (crrTop + h - y < h) {// element already in view
        crrTop = y;
        fx.end = y;
        return true;
      }
    } else {
      //TODO check if already in view
    }
    if (nItemNav !== 'undefined' && nItemNav.hasClass('fixed')) {
      crrTop -= nItemNav.height();//TODO remove if workaround found
    }
    if (targetTop != crrTop) {
      targetTop = crrTop;
      fx.end = targetTop;//TODO is there a way to do this smoothly?
    }
    return false;
  };
  if (!adjustAnimation(0, {})) {
    $('html, body').stop().animate({
      scrollTop: targetTop
    }, {
      'duration': duration,
      'step': adjustAnimation
    });
  }
}

/**
 * Prepares all modal boxes. Registers all box UI events.
 */
function prepareModalBoxes() {
  // fill modal boxes to save changes on item or its resources
  prepareModalBox('learningGoals', 'edit', 5, saveChanges);
  prepareModalBox('video', 'edit', 1, saveChanges);
  prepareModalBox('script', 'edit', 5, saveChanges);
  prepareModalBox('quiz', 'edit', 5, saveChanges);
  prepareModalBox('furtherReading', 'edit', 5, saveChanges);

  // fill modal boxes to add a MOOC item
  prepareModalBox('addLesson', 'add-lesson', 1, function(idSection, value, summary) {
    addLesson(value, _index.item, summary, function() {
      reloadPage();
    });
  });
  prepareModalBox('addUnit', 'add-unit', 1, function(idSection, value, summary) {
    addUnit(value, _index.item, summary, function() {
      reloadPage();
    });
  });
  prepareModalBox('createMooc', 'create-mooc', 1, function(idSection, value, summary) {
    createMooc(value, summary, function() {
      reloadPage();
    });
  }).find('.btn-save').prop('disabled', false);

  // make boxes closable via button
  $('.modal-box').each(function() {
    var modal = $(this);
    modal.find('.btn-close').click(function() {
      closeModalBox(modal);
      return false;
    });
  });
  // make boxes closable via background click
  $('.modal-box > .background').click(function(e) {
    closeModalBox($(e.target).parent());
    return false;
  });
  // make boxes closable via ESC key
  $('.modal-box').on('keydown', function(e) { 
    if (e.which == 27) {
      closeModalBox($(this));
    }
  });
}

/**
 * Creates a modal box.
 * @param {String} identifier of the parameter/resource the modal box will be responsible for
 * @param {String} intend identifier of the box (add/edit)
 * @param {int} number of lines needed for editing
 * @param {function} onSave callback
 * @return {jQuery} node of the modal box created
 */
function prepareModalBox(idSection, intentType, numLines, finishCallback) {
  // create modal box structure
  var modalBox = $('#modal-' + intentType + '-' + idSection);
  modalBox.append($('<div>', {
    'class': 'background'
  }));
  var boxContent = $('<div>', {
    'class': 'content border-box'
  });
  boxContent.append($('<div>', {
    'class': 'btn-close'
  }));
  var editFieldset = $('<fieldset>', {
    'class': 'edit-field'
  });
  // label and textarea for value
  editFieldset.append($('<label>', {
    'for': 'edit-field-' + idSection,
    'class': 'label-title',
    'text': AddinMooc_CONFIG.message('UI_MODAL_LABEL_TITLE_' + idSection)
  }));
  var editField;
  if (numLines > 1) {
    editField = $('<textarea>', {
      'class': 'border-box',
      'id': 'edit-field-' + idSection
    });
  } else {
    editField = $('<input>', {
      'class': 'border-box',
      'id': 'edit-field-' + idSection,
      'type': 'text'
    });
  }
  editFieldset.append(editField);
  // label and input box for edit summary
  editFieldset.append($('<label>', {
    'for': 'summary-' + idSection,
    'class': 'label-summary',
    'text': AddinMooc_CONFIG.message('UI_MODAL_LABEL_SUMMARY')
  }));
  var ibSummary = $('<input>', {
    'id': 'summary-' + idSection,
    'class': 'border-box summary',
    'type': 'text'
  });
  editFieldset.append(ibSummary);
  // help text
  var divHelpText = $('<div>', {
    'class': 'help'
  }).html(AddinMooc_CONFIG.message('UI_MODAL_HELP_' + idSection, _fullPath));
  editFieldset.append(divHelpText);
  boxContent.append(editFieldset);
  //Q: why not put in edit fieldset?
  // finish button
  var btnSave = $('<input>', {
    'class': 'btn-save',
    'disabled': true,
    'type': 'button',
    'value': AddinMooc_CONFIG.message('UI_MODAL_BTN_' + intentType)
  });
  boxContent.append(btnSave);
  btnSave.click(function() {
    if (!btnSave.prop('disabled')) {
      btnSave.prop('disabled', true);
      finishCallback(idSection, editField.val(), ibSummary.val());
    }
    return false;
  });
  modalBox.append(boxContent);
  return modalBox;
}

/**
 * Fills all modal boxes.
 * @param {Object} MOOC item the boxes will enable to edit
 */
function fillModalBoxes(item) {
  // inject item data
  $('#edit-field-learningGoals').append(item.getParameter(PARAMETER_KEY.LEARNING_GOALS));
  $('#modal-edit-learningGoals').find('.btn-save').prop('disabled', false);
  $('#edit-field-video').val(item.getParameter(PARAMETER_KEY.VIDEO));
  $('#modal-edit-video').find('.btn-save').prop('disabled', false);
  $('#edit-field-furtherReading').append(item.getParameter(PARAMETER_KEY.FURTHER_READING));
  $('#modal-edit-furtherReading').find('.btn-save').prop('disabled', false);
  $('#modal-add-lesson-addLesson').find('.btn-save').prop('disabled', false);
  $('#modal-add-unit-addUnit').find('.btn-save').prop('disabled', false);

  // retrieve and inject additional resources
  var taScript = $('#edit-field-script');
  item.retrieveScript(function(scriptText) {
    taScript.text(scriptText).html();
    $('#modal-edit-script').find('.btn-save').prop('disabled', false);
  }, function(jqXHR) {
    if (jqXHR.status == 404) {// script missing
      taScript.text(AddinMooc_CONFIG.message('DEFVAL_SCRIPT', item.header.type)).html();
    }
    $('#modal-edit-script').find('.btn-save').prop('disabled', false);
  });
  var taQuiz = $('#edit-field-quiz');
  item.retrieveQuiz(function(quizText) {
    taQuiz.text(quizText).html();
    $('#modal-edit-quiz').find('.btn-save').prop('disabled', false);
  }, function(jqXHR) {
    if (jqXHR.status == 404) {// quiz missing
      taQuiz.text(AddinMooc_CONFIG.message('DEFVAL_QUIZ')).html();
      $('#modal-edit-quiz').find('.btn-save').prop('disabled', false);
    }
  });
}

/**
 * Closes a modal box.
 * @param {jQuery} modal box to be closed
 */
function closeModalBox(modal) {
  nItemNav.css('z-index', 1001);
  modal.parent().parent().css('z-index', 1);
  modal.fadeOut();
}

/**
 * Saves changes to an item or one of its resources. Updates the MOOC index.
 * @param {String} identifier of the updated parameter/resource
 * @param {String} section value
 * @param {String} edit summary appendix
 */
function saveChanges(idSection, value, summary) {//Q: isn't idSection == key?
  var sucCallback = function() {
    reloadPage('#' + idSection);
  };
  if (idSection === 'script') {// update script resource
    if (_index.item.script === null) {
      // add category
      value += '\n<noinclude>[[category:' + _index.base + '-MOOC]]</noinclude>';
    }
    updateScript(_index.item, value, summary, sucCallback);
  } else if (idSection === 'quiz') {// update quiz resource
    if (_index.item.quiz === null) {
      // add category
      value += '\n<noinclude>[[category:' + _index.base + '-MOOC]][[category:Quizzes]]</noinclude>';
    }
    updateQuiz(_index.item, value, summary, sucCallback);
  } else {// update index parameter
    var key = null;
    if (idSection === 'learningGoals') {
      key = PARAMETER_KEY.LEARNING_GOALS;
      value = value.replace(/(^|\n)\*/g, '\n#');
    } else if (idSection === 'video') {
      key =  PARAMETER_KEY.VIDEO;
      value = value.replace(/(^|\n)\*/g, '');
    } else if (idSection === 'furtherReading') {
      key = PARAMETER_KEY.FURTHER_READING;
      value = value.replace(/(^|\n)\*/g, '\n#');
    }
    if (key !== null) {
      if (summary === '') {
        summary = AddinMooc_CONFIG.message('DEFSUM_EDIT_' + idSection);
      }
      _index.item.setParameter(key, value);
      updateIndex(_index.item, summary, sucCallback);
    }
  }
}

/**
 * Injects the interface to ask a question into a section.
 * @param {String} section identifier
 * @param {String} title of the talk page a question would be placed on
 * @param {boolean} inject a button only into section header if set to true
 */
function insertAskQuestionUI(identifier, talkPageTitle, ownUi) {
  var nSection = $('#' + identifier);
  var nContent = nSection.children('.content');
  var btn = nSection.children('.header').find('.btn-ask-question');
  if (ownUi) {// create UI
    var ui = createAskQuestionUI(identifier, talkPageTitle);
    nContent.append(ui);
    btn.click(function() {
      // scroll to UI and focus title box
      scrollIntoView(ui, 'bottom');
      ui.children('.title').focus();
    });
  } else {// scroll to discussion ui
    btn.click(function() {
      var nDiscussionUi = $('#discussion').find('.ask-question');
      scrollIntoView(nDiscussionUi, 'bottom');
      nDiscussionUi.children('.title').focus();
    });
  }
}

/**
 * Creates the interface to ask a question.
 * @param {String} identifier of the parameter/resource the interface belongs to
 * @param {String} title of the talk page a question would be placed on
 * @return {jQuery} node of the interface created
 */
function createAskQuestionUI(identifier, talkPageTitle) {
  var ui = $('<div>', {
    'class': 'ask-question'
  });
  // question title
  var lbTitle = $('<label>', {
    'for': 'thread-title-' + identifier,
    'text': AddinMooc_CONFIG.message('UI_ASK_LABEL_TITLE')
  });
  ui.append(lbTitle);
  var iTitle = $('<input>', {
    'class': 'title border-box',
    'id': 'thread-title-' + identifier,
    'type': 'text'
  });
  ui.append(iTitle);
  // question content
  var lbContent = $('<label>', {
    'for': 'thread-content-' + identifier,
    'text': AddinMooc_CONFIG.message('UI_ASK_LABEL_CONTENT')
  });
  ui.append(lbContent);
  var minRows = 3;
  var teaContent = $('<textarea>', {
    'class': 'border-box',
    'id': 'thread-content-' + identifier,
    'rows': minRows
  });
  ui.append(teaContent);
  $(document).on('input.textarea', '#' + teaContent.attr('id'), function() {
    var rows = this.value.split('\n').length;
    this.rows = rows < minRows ? minRows : rows;
  });
  // ask button
  var btnAsk = $('<input>', {
    'class': 'btn-ask',
    'type': 'button',
    'value': AddinMooc_CONFIG.message('UI_ASK_BTN_ASK')
  });
  ui.append(btnAsk);
  btnAsk.click(function() {
    if (btnAsk.prop('disabled')) {
      return;
    }
    btnAsk.prop('disabled', true);
    // add section to talk page
    var title = iTitle.val();
    if (title.length > 0) {
      var content = stripPost(teaContent.val());
      content += ' --~~~~';
      asking = true;
      _index.retrieveItem(function(item) {
        item.discussion = discussion;
        addThread(item, getTalkPage(talkPageTitle), title, content, function() {
          reloadPage('#discussion');
        });
      });
    } else {
      notifyUser('q-no-title', null, {//TODO: display error on page and scroll in view
        'class': 'error'
      });
    }
  });
  var blackIn = function() {
    ui.css('opacity', 1);
  };
  var greyOut = function() {
    if (ui.children(':focus').length > 0) {// dont grey out if having focus
      return;
    }
    ui.css('opacity', 0.6);
  };
  ui.mouseleave(greyOut);
  ui.focusout(greyOut);
  greyOut();
  ui.mouseenter(blackIn);
  ui.focusin(blackIn);
  return ui;
}

/**
 * Creates the interface to reply to a post.
 * @param {Object} postData object including root thread and post to reply to
 * @return {jQuery} node of the interface created
 */
function createReplyUI(postData) {
  var ui = $('<div>', {
    'class': 'ui-reply'
  });
  // reply content
  var lbContent = $('<label>', {
    'for': 'reply-content-' + postData.post.id,
    'text': AddinMooc_CONFIG.message('UI_REPLY_LABEL_CONTENT')
  });
  ui.append(lbContent);
  var minRows = 3;
  var teaContent = $('<textarea>', {
    'class': 'border-box',
    'id': 'reply-content-' + postData.post.id,
    'rows': minRows
  });
  ui.append(teaContent);
  $(document).on('input.textarea', '#' + teaContent.attr('id'), function() {
    var rows = this.value.split('\n').length;
    this.rows = rows < minRows ? minRows : rows;
  });
  // reply button
  var btnReply = $('<input>', {
    'class': 'btn-reply',
    'type': 'button',
    'value': 'Send reply'
  });
  btnReply.click(function() {
    if (btnReply.prop('disabled')) {
      return;
    }
    btnReply.prop('disabled', true);
    var content = stripPost(teaContent.val());
    if (content.length > 0) {
      var post = postData.post;
      var thread = postData.thread;
      var reply = Post(0, post.level + 1, [ content ], [], createPseudoSignature('--~~~~'));
      post.replies.push(reply);
      // save thread to its talk page
      _index.retrieveItem(function(item) {
        item.discussion = discussion;
        saveThread(item, thread, function() {
          reloadPage('#discussion');
        });
      });
    }
  });
  ui.append(btnReply);
  return ui;
}

/**
 * Renders a post into a node.
 * @param {Object} post instance to render
 * @return {jQuery} node representing the given post
 */
function renderPost(post) {
  // main node
  var nPost = $('<li>', {
    'class': 'post',
    'id': 'post-' + post.id
  });
  // content
  var nContent = $('<div>', {
    'class': 'content'
  });
  // post message
  var nMessage = $('<div>', {
    'class': 'message'
  });
  // message text
  var nMessageText = $('<div>', {
  'class': 'text'
  }).html(post.htmlContent);
  nMessage.append(nMessageText);
  // meta information
  var sSignature = '';
  if (post.signature !== null) {
    sSignature = post.signature.tostring();
  }
  var nMeta = $('<div>', {
    'class': 'meta',
    'text': sSignature
  });
  nMeta.toggle(false);
  nMessage.append(nMeta);
  // reply overlay
  var nOverlay = renderPostOverlay();
  nMessage.prepend(nOverlay);
  nMessage.mouseenter(function() {
    nOverlay.stop(true).fadeIn();
    nMeta.stop(true).fadeIn();
  });
  nMessage.mouseleave(function() {
    nOverlay.stop(true).fadeOut();
    nMeta.stop(true).fadeOut();
  });
  nContent.append(nMessage);
  // replies
  nContent.append(renderReplies(post));
  nPost.append(nContent);
  return nPost;
}

/**
 * Creates the overlay to show a reply interface.
 * @return {jQuery} node of the overlay created
 */
function renderPostOverlay() {
  var nOverlay = $('<div>', {
    'class': 'overlay'
  });
  var nBackground = $('<div>', {
    'class': 'background'
  });
  var nContent = $('<div>', {
    'class': 'content'
  });
  var btnReply = $('<div>', {
    'class': 'btn-reply',
    'text': AddinMooc_CONFIG.message('UI_REPLY_BTN_REPLY')
  });
  var ui = null;
  btnReply.click(function() {// inject reply UI to reply to post
    var visible = false;
    if (ui === null) {
      var nPost = nOverlay.parent().parent().parent();
      var postId = nPost.attr('id').substring(5);
      var postData = findPostInThread(postId);
      ui = createReplyUI(postData);
      nPost.children('.content').children('.replies').append(ui);
    } else {
      visible = ui.css('display') != 'none';
      ui.toggle('fast');
    }
    if (!visible) {
      // hide all other reply UI
      $('.ui-reply').not(ui).toggle(false);
      // scroll to UI
      scrollIntoView(ui, 'bottom');
      ui.children('textarea').focus();
    }
  });
  nContent.append(btnReply);
  nOverlay.append(nBackground);
  nOverlay.append(nContent);
  return nOverlay;
}

/**
 * Renders the replies of a post.
 * @param {Object} post with replies to render
 * @return {jQuery} node with all rendered reply posts
 */
function renderReplies(post) {
  var nReplies = $('<ol>', {
    'class': 'replies'
  });
  for (var i = 0; i < post.replies.length; ++i) {
    nReplies.append(renderReply(post.replies[i]));
  }
  return nReplies;
}

/**
 * Render a reply post into a node.
 * @param {Object} post instance to render
 * @return {jQuery} node representing the given reply post
 */
function renderReply(reply) {
  var nReply = renderPost(reply);
  nReply.addClass('reply');
  return nReply;
}

/**
 * Renders a thread into a node.
 * @param {Object} thread instance to render
 * @return {jQuery} node representing the given thread
 */
function renderThread(thread) {
  var nThread = renderPost(thread);
  nThread.addClass('thread');
  // add thread header containing title and statistics
  var nHeader = renderThreadHeader(thread);
  nThread.prepend(nHeader);
  // make thread collapsible
  nThread.children('.content').addClass('collapsible');
  nHeader.click(function() {
    if (nThread.hasClass('collapsed')) {
      expandThread(nThread, thread);
    } else {
      collapseThread(nThread, thread);
    }
  return false;
  });
  // show warning if unsigned
  if (thread.signature === null) {
    nThread.find('.meta').addClass('warning').text('No one signed this thread.');
  }
  return nThread;
}

/**
 * Creates the header of a certain thread.
 * @param {Object} thread instance to create the header for
 * @return {jQuery} header node created
 */
function renderThreadHeader(thread) {
  // thread title
  var nHeader = $('<h2>', {
    'class': 'header title',
    'text': thread.title
  });
  // number of replies
  var nNumReplies = $('<div>', {
    'class': 'num-replies',
    'text': AddinMooc_CONFIG.message('UI_THREAD_LABEL_HEADER', thread.getNumPosts() - 1)
  });
  nHeader.append(nNumReplies);
  return nHeader;
}

/*####################
  # MEDIAWIKI API WRAPPERS
  # the functions have different intends and
  # 1.) abstract from API calls
  # 2.) chain multiple, successive calls
  ####################*/

/**
 * Request a wiki page's plain wikitext content.
 * Uses 'action=raw' to get the page content.
 * @param {String} title of the wiki page
 * @param {int} section within the wiki page; pass 0 to retrieve whole page
 * @param {function} success callback (String pageContent)
 * @param {function} (optional) failure callback (Object jqXHR: HTTP request object)
 */
function doPageContentRequest(pageTitle, section, sucCallback, errorCallback) {/* Q: feature supported by mw.Api? */
  var url = AddinMooc_CONFIG.get("MW_ROOT_URL") + "?action=raw&title=" + pageTitle;
  if (section !== null) {
    url += "&section=" + section;
  }
  $.ajax({
    url: url,
    cache: false
  }).fail(function(jqXHR) {
    AddinMooc_CONFIG.log(1, 'ERR_WCONTENT_REQ', pageTitle, section, jqXHR.status);
    if (typeof errorCallback !== 'undefined') {
      errorCallback(jqXHR);
    }
  }).done(sucCallback);
}

/**
 * Retrieves edit tokens for any number of wiki pages.
 * @param {Array<String>} page titles of the wiki pages
 * @param {function} success callback (Object editTokens: editTokens.get(pageTitle) = token)
 */
function doEditTokenRequest(pageTitles, sucCallback) {
  var sPageTitles = pageTitles.join('|');
  // get edit tokens
  var tokenData = {
    'intoken': 'edit|watch'
  };
  $.ajax({
    type: "POST",
    url: AddinMooc_CONFIG.get("MW_API_URL") + "?action=query&prop=info&format=json&titles=" + sPageTitles,
    data: tokenData
  }).fail(function(jqXHR) {
    AddinMooc_CONFIG.log(1, 'ERR_WTOKEN_REQ', sPageTitles, jqXHR.status);
  }).done(function(response) {
    var editTokens = parseEditTokens(response);
    if (editTokens.hasTokens()) {
      sucCallback(editTokens);
    } else {
      AddinMooc_CONFIG.log(1, 'ERR_WTOKEN_MISSING', sPageTitles, JSON.stringify(response));
    }
  });
}

/**
 * Edits a wiki page. (non-existing pages will be created automatically)
 * @param {String} title of the wiki page
 * @param {int} section within the wiki page; pass 0 to edit whole page
 * @param {String} edited page content
 * @param {String} edit summary
 * @param {function} success callback
 */
function doEditRequest(pageTitle, section, content, summary, sucCallback) {/* Q: what errors are possible? */
  AddinMooc_CONFIG.log(0, 'LOG_WEDIT', pageTitle, section);
  doEditTokenRequest([ pageTitle ], function(editTokens) {
    var editToken = editTokens.get(pageTitle);
    var editData = {
      'title': pageTitle,
      'text': content,
      'summary': summary,
      'watchlist': 'watch',
      'token': editToken
    };
    if (section !== null) {
      editData.section = section;
    }
    $.ajax({
      type: "POST",
      url: AddinMooc_CONFIG.get("MW_API_URL") + "?action=edit&format=json",
      data: editData
    }).fail(function(jqXHR) {
      AddinMooc_CONFIG.log(1, 'ERR_WEDIT_REQ', pageTitle, jqXHR.status);
    }).done(function(response) {//TODO handle errors
      AddinMooc_CONFIG.log(0, 'LOG_WEDIT_RES', JSON.stringify(response));
      sucCallback();
    });
  });
}

/**
 * Adds a section to a wiki page. (non-existing pages will be created automatically)
 * @param {String} title of the wiki page
 * @param {String} title of the new section
 * @param {String} content of the new section
 * @param {String} edit summary
 * @param {function} success callback
 */
function addSectionToPage(pageTitle, sectionTitle, content, summary, sucCallback) {
  AddinMooc_CONFIG.log(0, 'LOG_WADD', pageTitle, sectionTitle);
  doEditTokenRequest([ pageTitle ], function(editTokens) {
    var editToken = editTokens.get(pageTitle);
    var editData = {
      'title': pageTitle,
      'section': 'new',
      'sectiontitle': sectionTitle,
      'text': content,
      'summary': summary,
      'watchlist': 'watch',
      'token': editToken
    }; 
    $.ajax({
      type: "POST",
      url: AddinMooc_CONFIG.get("MW_API_URL") + "?action=edit&format=json",
      data: editData
    }).fail(function(jqXHR) {
      AddinMooc_CONFIG.log(1, 'ERR_WADD_REQ', pageTitle, sectionTitle, jqXHR.status);
    }).done(function(response) {//TODO handle errors
      AddinMooc_CONFIG.log(0, 'LOG_WADD_RES', JSON.stringify(response));
      sucCallback();
    });
  });
}

/**
 * Parses a server response containing one or multiple edit tokens.
 * @param {JSON} tokenResponse
 * @return {Object} edit tokens object - you can retrieve the edit token by passing the page title to the object's 'get'-function
 */
function parseEditTokens(tokenResponse) {
  var hasTokens = false;
  var editTokens = {
    'tokens': [],
    'add': function(title, edittoken) {
      var lTitle = title.toLowerCase();
      AddinMooc_CONFIG.log(0, 'LOG_WTOKEN_TOKEN', title, edittoken);
      this.tokens[lTitle] = edittoken;
      hasTokens = true;
    },
    'get': function(title) {
      return this.tokens[title.toLowerCase()];
    },
    'hasTokens': function() {
      return hasTokens;
    }
  };
  var path = ['query', 'pages'];
  var crr = tokenResponse;
  for (var i = 0; i < path.length; ++i) {
    if (crr && crr.hasOwnProperty(path[i])) {
      crr = crr[path[i]];
    } else {
      AddinMooc_CONFIG.log(1, 'ERR_WTOKEN_PARSING', path[i]);
      crr = null;
      break;
    }
  }
  if (crr) {
    var pages = crr;
    for (var pageId in pages) {
      // page exists
      if (pages.hasOwnProperty(pageId)) {
        var page = pages[pageId];
        editTokens.add(page.title, page.edittoken);
      }
    }
  }
  return editTokens;
}

/**
 * Retrieves the index of a MOOC.
 * @param {String} title of the MOOC index page
 * @param {int} section within the index page; pass 0 to retrieve whole page
 * @param {function} success callback (String indexContent)
 */ 
function getIndex(title, section, sucCallback) {
  doPageContentRequest(title, section, sucCallback);
}

/**
 * Retrieves the script of a MOOC item.
 * @param {Object} MOOC item
 * @param {function} success callback (String scriptContent)
 * @param {function} (optional) failure callback (Object jqXHR: HTTP request object)
 */
function getScript(item, sucCallback, errorCallback) {
  doPageContentRequest(item.fullPath + '/script', 0, sucCallback, errorCallback);
}

/**
 * Retrieves the quiz of a MOOC item.
 * @param {Object} MOOC item
 * @param {function} success callback (String quizContent)
 * @param {function} (optional) failure callback (Object jqXHR: HTTP request object)
 */
function getQuiz(item, sucCallback, errorCallback) {
  doPageContentRequest(item.fullPath + '/quiz', 0, sucCallback, errorCallback);
}

/**
 * Updates the script of a MOOC item.
 * @param {Object} MOOC item
 * @param {String} updated script content
 * @param {String} edit summary; uses generated summary if empty
 * @param {function} success callback
 */
function updateScript(item, scriptText, summary, sucCallback) {
  var editSummary = summary;
  if (editSummary === '') {
    editSummary = 'script update for MOOC ' + item.header.type + ' ' + item.fullPath;
  }
  doEditRequest(item.fullPath + '/script', 0, scriptText, editSummary, sucCallback);
}

/**
 * Updates the quiz of a MOOC item.
 * @param {Object} MOOC item
 * @param {String} updated quiz content
 * @param {String} edit summary; uses generated summary if empty
 * @param {function} success callback
 */
function updateQuiz(item, quizText, summary, sucCallback) {
  var editSummary = summary;
  if (editSummary === '') {
    editSummary = 'quiz update for MOOC ' + item.header.type + ' ' + item.fullPath;
  }
  doEditRequest(item.fullPath + '/quiz', 0, quizText, editSummary, sucCallback);
}

/**
 * Updates the MOOC index containing the given MOOC item.
 * @param {Object} MOOC item
 * @param {String} edit summary appendix; will be appended to a generated summary specifying the MOOC item passed
 * @param {function} success callback
 */
function updateIndex(item, summaryAppendix, sucCallback) {
  var summary = item.header.type + ' ' + item.header.path + ': ' + summaryAppendix;
  if (item.header.path === null) {// changing root item
    summary = item.header.type + ':' + summaryAppendix;
  }
  doEditRequest(item.index.title, item.indexSection, item.tostring(), summary, sucCallback);
}

/**
 * Creates a wiki page.
 * @param {String} title of the new page
 * @param {String} content of the new page
 * @param {String} edit summary
 * @param {function} success callback
 */
function createPage(pageTitle, content, summary, sucCallback) {
  doEditRequest(pageTitle, 0, content, summary, sucCallback);
}

/**
 * Adds a child item to a MOOC item.
 * @param {String} type of the new item
 * @param {String} name of the new item
 * @param {Object} MOOC item the child will be added to
 * @param {String} edit summary appendix for MOOC index edit; uses generated summary appendix if empty
 * @param {function} success callback
 */
function addChild(type, name, parent, summary, sucCallback) {
  // add item to parent
  var parentHeader = parent.header;
  var header = Header(parentHeader.level + 1, type, name, null);
  parent.childLines.push(header.tostring());
  AddinMooc_CONFIG.log(0, 'LOG_ADD_CHILD', parentHeader.type, parentHeader.title, header.tostring());
  // update MOOC index at parent position
  var itemIdentifier = type + ' ' + parentHeader.path + '/' + name;
  if (parentHeader.path === null) {// parent is root
    itemIdentifier = type + ' ' + name;
  }
  if (summary === '') {
    summary = itemIdentifier + ' added';
  }
  updateIndex(parent, summary, function() {
    // create item page
    doEditRequest(parent.fullPath + '/' + name, 0, parent.getInvokeCode(), 'invoke page for MOOC ' + itemIdentifier + ' created', sucCallback);
  });
}

/**
 * Adds a lesson to a MOOC.
 * @param {String} lesson name
 * @param {Object} MOOC root item
 * @param {String} edit summary appendix for MOOC index edit; uses generated summary appendix if empty
 * @param {function} success callback
 */
function addLesson(name, item, summary, sucCallback) {
  addChild('lesson', name, item, summary, sucCallback);
}

/**
 * Adds an unit to a MOOC lesson.
 * @param {String} unit name
 * @param {Object} lesson the unit will be added to
 * @param {String} edit summary appendix for MOOC index edit; uses generated summary appendix if empty
 * @param {function} success callback
 */
function addUnit(name, item, summary, sucCallback) {
  addChild('unit', name, item, summary, sucCallback);
}

/**
 * Creates a MOOC.
 * @param {String} MOOC name
 * @param {String} edit summary for category, MOOC overview and MOOC index page
 * @param {function} success callback
 */
function createMooc(title, summary, sucCallback) {
  createPage('Category:' + title, '{{#invoke:Mooc|overview|base=' + title + '}}\n<noinclude>[[category:MOOC]]</noinclude>', summary, function() {// create category with overview
    createPage(title, '{{#invoke:Mooc|overview|base=' + title + '}}', summary, function() {// create MOOC overview page
      createPage(title + '/MoocIndex', '--MoocIndex for MOOC @ ' + title, summary, sucCallback);// create MOOC index
    });
  });
}

/**
 * Adds a thread to a talk page belonging to a MOOC item. Updates the item's discussion statistic in MOOC index.
 * @param {Object} MOOC item the talk page belongs to
 * @param {Object} talk page object
 * @param {String} title of the new thread
 * @param {String} content of the new thread
 * @param {function} success callback
 */
function addThread(item, talkPage, title, content, sucCallback) {//TODO: use updateIndex
  item.setParameter(PARAMETER_KEY.NUM_THREADS, (item.discussion.threads.length + 1).toString());
  item.setParameter(PARAMETER_KEY.NUM_THREADS_OPEN, (item.discussion.getNumOpenThreads() + 1).toString());
  addSectionToPage(talkPage.title, title, content, 'q:' + title, function() {
    // update discussion statistic in MOOC index
    doEditRequest(item.index.title, item.indexSection, item.tostring(), 'new thread in item discussion', sucCallback);
  });
}

/**
 * Updates a thread on a talk page belonging to a MOOC item. Updates the item's discussion statistic in MOOC index.
 * @param {Object} MOOC item the talk page belongs to
 * @param {Object} thread object
 * @param {function} success callback
 */
function saveThread(item, thread, sucCallback) {//TODO: use updateIndex
  item.setParameter(PARAMETER_KEY.NUM_THREADS, item.discussion.threads.length.toString());
  item.setParameter(PARAMETER_KEY.NUM_THREADS_OPEN, item.discussion.getNumOpenThreads().toString());
  doEditRequest(thread.talkPage.title, thread.section, thread.tostring(), 'replied to "' + thread.title + '"', function() {
    // update discussion statistic in MOOC index
    doEditRequest(item.index.title, item.indexSection, item.tostring(), 'new reply in item discussion', sucCallback);
  });
}

/**
 * Parses wikitext.
 * @param {String} wikitext to be parsed
 * @param {function} success callback (String parsedWikitext)
 * @param {function} (optional) failure callback
 */
function parseThreads(unparsedContent, sucCallback, errCallback) {
  AddinMooc_CONFIG.log(0, 'LOG_WPARSE', unparsedContent);
  var api = new mw.Api();
  var promise = api.post({
    'action': 'parse',
    'contentmodel': 'wikitext',
    'disablepp': true,
    'text': unparsedContent
  });
  promise.done(function(response) {
    AddinMooc_CONFIG.log(0, 'LOG_WPARSE_RES', JSON.stringify(response));
    var wikitext = response.parse.text['*'];
    sucCallback(wikitext);
  });
  if (typeof errCallback !== 'undefined') {
    promise.fail(errCallback);
  }
}

/**
 * Retrieves the URLs of any number of video files.
 * @param {Array<String>} array of titles of the files to retrieve an URL for (WARNING: should not include '_' to access the URL mapping in success callback correctly)
 * @param {function} callback when the URLs were retrieved successfully (An array mapping (page title) -> (url) will be passed. The page titles will not contain '_' but spaces.)
 */
function getVideoUrls(fileTitles, sucCallback) {
  //WTF: imageinfo does also work on video files
  var sFileTitles = fileTitles.join('|');
  var api = new mw.Api();
  api.get({
    action: 'query',
    prop: 'videoinfo',
    titles: sFileTitles,
    viprop: 'url'
  }).done(function(data) {
    var path = ['query', 'pages'];
    var crr = data;
    for (var i = 0; i < path.length; ++i) {
      if (crr && crr.hasOwnProperty(path[i])) {
        crr = crr[path[i]];
      } else {
        AddinMooc_CONFIG.log(1, 'ERR_WQUERY_VIDEO', path[i]);
        crr = null;
        break;
      }
    }
    var fileUrls = [];
    if (crr) {
      var pages = crr;
      for (var pageId in pages) {
        // page exists
        if (pages.hasOwnProperty(pageId)) {
          var page = pages[pageId];
          fileUrls[page.title] = page.videoinfo[0].url;
          AddinMooc_CONFIG.log(0, 'LOG_WQUERY_VIDEO_URL', page.title, page.videoinfo[0].url);
        }
      }
      sucCallback(fileUrls);
    }
  });
}

/*####################
  # INDEX UTILITIES
  # helper functions to load objects from and work with the MOOC index
  ####################*/
 
 /**
 * Creates a header instance holding identification data of the MOOC item.
 * @param {int} item level
 * @param {String} item type
 * @param {String} item title
 * @param {String} item path (relative to MOOC base)
 * @return {Object} MOOC item header to identify the item and write to MOOC index
 */
function Header(level, type, title, path) {
  return {
    'level': level,
    'path': path,
    'title': title,
    'type': type,
    'tostring': function() {
      var intendation = strrep('=', this.level);
      return intendation + this.type + '|' + this.title + intendation;
    }
  };
}

/**
 * Creates an item instance holding data extracted from MOOC index.
 * @param {Object} item header
 * @param {Object} MOOC index
 * @return {Object} MOOC item to get parameters and write to MOOC index
 */
function Item(header, index) {
  var loadingScript = false;
  var loadingQuiz = false;
  return {
    'childLines': [],
    'discussion': null,
    'fullPath': _fullPath,
    'header': header,
    'index': index,
    'indexSection': index.itemSection,
    'parameterKeys': [],
    'parameters': {},
    'script': null,
    'quiz': null,
    /**
     * @return {String} invoke code used for the current item
     */
    'getInvokeCode': function() {
      return '{{#invoke:Mooc|render|base=' + index.base + '}}';
    },
    /**
     * Gets the value for an item parameter.
     * @param {String} parameter key
     * @return {?} Value stored for the parameter key passed. May be undefined.
     */
    'getParameter': function(key) {
      return this.parameters[key];
    },
    /**
     * Sets the value for an item parameter.
     * @param {String} parameter key
     * @param {?} parameter value
     */
    'setParameter': function(key, value) {
      this.parameters[key] = value;
      if ($.inArray(key, this.parameterKeys) == -1) {
        this.parameterKeys.push(key);
      }
    },
    /**
     * Retrieves the script resource for this item.
     * @param {function} success callback (String scriptContent)
     * @param {function} (optional) failure callback (Object jqXHR: HTTP request object)
     */
    'retrieveScript': function(sucCallback, errCallback) {
      if (this.script !== null) {
        sucCallback(this.script);
      } else if (!loadingScript) {
        loadingScript = true;
        getScript(this, sucCallback, errCallback);
      } else {
        // does not happen
      }
    },
    /**
     * Retrieves the quiz resource for this item.
     * @param {function} success callback (String quizContent)
     * @param {function} (optional) failure callback (Object jqXHR: HTTP request object)
     */
    'retrieveQuiz': function(sucCallback, errCallback) {
      if (this.quiz !== null) {
        sucCallback(this.quiz);
      } else if (!loadingQuiz) {
        loadingQuiz = true;
        getQuiz(this, sucCallback, errCallback);
      } else {
        // does not happen
      }
    },
    /**
     * @return MOOC index content for this item
     */
    'tostring': function() {
      var lines = [];
      // header line
      if (this.indexSection !== null) {// except root item
        lines.push(this.header.tostring());
      }
      // parameters
      var key, value;
      this.parameterKeys.sort();
      for (var i = 0; i < this.parameterKeys.length; ++i) {
        key = this.parameterKeys[i];
        value = this.parameters[key];
        if (value.indexOf("\n") != -1) {// linebreak for multi line values
          lines.push('*' + key + '=\n' + value);
        } else {
          lines.push('*' + key + '=' + value);
        }
      }
      // children
      for (var c = 0; c < this.childLines.length; ++c) {
        lines.push(this.childLines[c]);
      }
      return lines.join('\n');
    }
  };
}

/**
 * Creates a MOOC index instance providing read access.
 * @param {String} MOOC page title
 * @param {String} MOOC base
 * @return {Object} MOOC index instance to retrieve item
 */
function MoocIndex(title, base) {
  var isLoading = false;
  return {
    'base': base,
    'item': null,
    'itemPath': null,
    'itemSection': null,
    'title': title,
    /**
     * Sets the current item. MUST be called before using this object.
     * @param {int} section of the item within the MOOC index
     * @param {String} absolute path of the item
     */
    'useItem': function(section, path) {
      this.itemSection = section;
      this.itemPath = path;
    },
    /**
     * Retrieves the current item from the MOOC index.
     * If the item is not cached this call will trigger a network request.
     * @param {function} success callback (String indexContent)
     * @param {function} (optional) failure callback (Object jqXHR: HTTP request object)
     */
    'retrieveItem': function(sucCallback, errCallback) {
      if (this.item !== null) {
        sucCallback(this.item);
      } else {
        var index = this;
        if (!isLoading) {// retrieve index and load item
          isLoading = true;
          getIndex(this.title, this.itemSection, function(indexContent) {
            var indexLines = splitLines(indexContent);
            var item;

            if (index.itemSection === null) {// root item
              item = Item(Header(0, 'mooc', index.base, null), index);
              // do not interprete index
              for (var i = 0; i < indexLines.length; ++i) {
                item.childLines.push(indexLines[i]);
              }
            } else {// index item
              var header = loadHeader(indexLines[0], index.base, index.itemPath);
              item = Item(header, index);
              // load properties and lines of child items
              var childLines = false;
              for (var i = 1; i < indexLines.length; i++) {
                if (!childLines) {
                  if (getLevel(indexLines[i]) > 0) {
                    childLines = true;
                  } else {
                    var property = loadProperty(indexLines, i);
                    item.setParameter(property.key, property.value);
                    i = property.iEnd;
                  }
                }
                if (childLines) {
                  item.childLines.push(indexLines[i]);
                }
              }
            }
            index.item = item;
            isLoading = false;
            sucCallback(item);
          });
        } else {// another process triggered network request
          setTimeout(function() {
            if (index.item !== null) {
              sucCallback(index.item);
            } else if (!isLoading && typeof(errCallback) !== 'undefined') {
              errCallback();
            }
          }, 100);
        }
      }
    }
  };
}

 /**
 * Loads the header of a MOOC item from its index header line.
 * @param {String} item's header line from MOOC index
 * @param {String} MOOC base
 * @param {String} item path (absolute path including MOOC base)
 * @return {Object} MOOC item header loaded from header line. Returns null if header line malformed.
 */
function loadHeader(line, base, fullPath) {
  var level = getLevel(line);
  if (level > 0) {
    var iSeparator = line.indexOf('|');
    if (iSeparator > -1) {
      var type = line.substring(level, iSeparator);
      var title = line.substring(iSeparator + 1, line.length - level);
      var path = fullPath.substring(base.length + 1);// relative path
      AddinMooc_CONFIG.log(0, 'LOG_INDEX_HEADER', type, title, level, path);
      return Header(level, type, title, path);
    }
  }
  AddinMooc_CONFIG.log(1, 'ERR_INDEX_HEADER', line);
  return null;
}

/**
 * Loads a parameter of an item.
 * @param {Array} MOOC index lines
 * @param {int} start index of the parameter within index
 * @return {Object} item parameter extracted (key, value) and index of last line related to parameter (iEnd)
 */
function loadProperty(indexLines, iLine) {
  var line = indexLines[iLine];
  var iSeparator = line.indexOf('=');
  if (iSeparator != -1) {
    var paramLines = [];
    var key = line.substring(1, iSeparator);
    // read parameter value
    var i = iLine;
    var value = line.substring(iSeparator + 1);
    do {
      if (i > iLine) {// multiline value
        if (paramLines.length === 0 && value.length > 0) {// push first line value if any
          paramLines.push(value);
        }
        paramLines.push(line);
      }
      i += 1;
      line = indexLines[i];
    } while(i < indexLines.length && line.substring(0, 1) !== '*' && getLevel(line) === 0);
    i -= 1;

    if (paramLines.length > 0) {
      value = paramLines.join('\n');
    }
    return {
      'iEnd': i,
      'key': key,
      'value': value
    };
  }
  return null;
}

/*####################
  # DISCUSSION UTILITIES
  # helper functions to work with discussion objects
  ####################*/

/**
 * Creates a discussion instance holding data extracted from a number of talk pages.
 * @return {Object} discussion instance d with
 * * {int} d.lastId: highest post identifier used
 * * d.lost
 * * {Array<Object>} d.talkPages: talk page instances
 * * {Array<Object>} d.threads: threads loaded from the talk pages
 * * {int} d.getNumPosts()
 * * {int} d.getNumOpenThreads()
 * * {String} d.tostring()
 */
function Discussion() {
  return {
    'lastId': 0,
    'lost': [],
    'talkPages' : [],
    'threads': [],
    'getNumPosts': function() {
      var numPosts = 0;
      for (var i = 0; i < this.threads.length; ++i) {
        numPosts += this.threads[i].getNumPosts();
      }
      return numPosts;
    },
    'getNumOpenThreads': function() {
      var numOpenThreads = 0;
      for (var i = 0; i < this.threads.length; ++i) {
        if (this.threads[i].getNumPosts() === 1) {
          numOpenThreads += 1;
        }
      }
      return numOpenThreads;
    },
    'tostring': function() {
      var value = [];
      for (var i = 0; i < this.threads.length; ++i) {
        value.push(this.threads[i].tostring());
      }
      for (var j = 0; j < this.lost.length; ++j) {
        value.push(this.lost[j]);
      }
      return value.join('\n');
    }
  };
}

/**
 * Creates a post instance holding data extracted from the talk page.
 * @param {int} unique post identifier
 * @param {int} post level
 * @param {Array<String>} post content lines
 * @param {Array<Object>} posts that are in reply to this post
 * @param {Object} signature object
 * @return {Object} post instance p with
 * * {int} p.id
 * * {String} p.content
 * * {String} p.htmlContent
 * * {Array<Object>} p.replies
 * * {Object} p.signature
 * * {int} p.getNumPosts()
 * * {boolean} p.isValid()
 * * {String} p.tostring()
 */
function Post(id, level, content, replies, signature) {
  return {
    'id': id,
    'content': content.join('\n'),
    'htmlContent': this.content,
    'level': level,
    'replies': replies,
    'signature': signature,
    'getNumPosts': function() {
      var num = 1;
      for (var i = 0; i < this.replies.length; ++i) {
        num += this.replies[i].getNumPosts();
      }
      return num;
    },
    'isValid': function() {
      // post malformed: signature missing
      return (this.signature !== null);
    },
    'tostring': function() {
      var value = [];
      var firstLine = strrep(':', this.level) + this.content;
      if (this.signature !== null) {
        firstLine += ' ' + this.signature.towikitext();
      }
      value.push(firstLine);
      for (var i = 0; i < this.replies.length; ++i) {
        value.push(this.replies[i].tostring());
      }
      return value.join('\n');
    }
  };
}

/**
 * Creates a talk page instance.
 * @param {String} title of the talk page
 * @return {Object} talk page instance t with
 * * {Array<Object>} t.threads: threads on the talk page
 * * {String} t.title: talk page title
 */
function TalkPage(title) {
  return {
    'threads': [],
    'title': title
  };
}

/**
 * Creates a thread instance holding data extracted from the talk page.
 * @param {String} thread title (gets stripped from leading/trailing whitespace)
 * @param {int} section of the thread within the talk page
 * @return {Object} thread instance t being a post instance with
 * * {Array<String>} t.content
 * * {Array<String>} t.lost
 * * {int} t.published
 * * {int} t.section
 * * {String} t.title
 */
function Thread(title, section) {
  var thread = Post(0, 0, [], [], null);
  thread.content = [];
  thread.lost = [];
  thread.published = 0;
  thread.section = section;
  thread.title = $.trim(title);
  thread.tostring = function() {
    var value = [];
    value.push('==' + this.title + '==');
    value.push(this.content);
    if (this.signature !== null) {
      value.push(this.signature.towikitext());
    }
    for (var i = 0; i < this.replies.length; ++i) {
      value.push(this.replies[i].tostring());
    }
    return value.join('\n');
  };
  return thread;
}

/**
 * Creates an empty signature object.
 * @param {String} value the object's "towikitext" function returns
 * @return {Object} empty signature object returning the given value in it's "towikitext" function
 */
function createPseudoSignature(value) {
  return {
    'towikitext': function() {
      return value;
    }
  };
}

/**
 * Cuts thread content to a certain length as a preview.
 * @param {Object} thread with content to be cut
 * @param {int} maximum number of characters for the preview
 * @return {String} thread content with maximum length passed plus '...'
 */
function cutThreadContent(thread, maxLength) {
  var div = document.createElement('div');
  div.innerHTML = thread.htmlContent;
  var text = div.textContent || div.innerText || '';
  
  if (thread.htmlContent.length <= maxLength) {
    return thread.htmlContent;
  }
  var cutContent = [];
  var crrLength = 0;
  var words = text.split(/\s/);
  for (var i = 0; i < words.length; ++i) {
    crrLength += words[i].length + 1;
    if (crrLength > maxLength) {
      break;
    }
    cutContent.push(words[i]);
  }
  return cutContent.join(' ') + '...';
}

/**
 * Searches a post for a certain post identifier. Includes post specified and all its replies.
 * @param {Object} post instance to search in
 * @param {int} searched post identifier
 * @return {Object} o with
 * * {Object} o.post: post instance with searched identifier
 * or null if not found in post specified
 */
function findPostInPost(post, postId) {
  if (post.id == postId) {
    return {
      'post': post
    };
  }
  for (var i = 0; i < post.replies.length; ++i) {
    var reply = post.replies[i];
    var result = findPostInPost(reply, postId);
    if (result !== null) {
      return result;
    }
  }
  return null;
}

/**
 * Searches all threads for a certain post identifier.
 * @param {int} searched post identifier
 * @return {Object} o with
 * * {Object} o.post: post instance with searched identifier
 * * {Object} o.thread: thread instance the post belongs to
 * or null if not found
 */
function findPostInThread(postId) {
  for (var i = 0; i < discussion.threads.length; ++i) {
    var result = findPostInPost(discussion.threads[i], postId);
    if (result !== null) {
      result.thread = discussion.threads[i];
      return result;
    }
  }
  return null;
}

/**
 * Retrieves the instance of a certain talk page. Creates a new instance if not existing.
 * @param {String} title of the talk page
 * @return {Object} talk page instance
 */
function getTalkPage(talkPageTitle) {
  for (var i = 0; i < discussion.talkPages.length; ++i) {
    if (discussion.talkPages[i].title === talkPageTitle) {
      return discussion.talkPages[i];
    }
  }
  // return empty talk page object
  return TalkPage(talkPageTitle);
}

/**
 * Loads a post from a talk page.
 * @param {Array<String>} text lines containing the post
 * @param {int} index of the line the post starts at
 * @param {int} highest used post identifier
 * @return {Object} o with
 * * {Object} o.post: post instance
 * * {int} o.iEnd: index of the line the post ends at
 * * {int} o.iLastId: highest used post identifier
 */
function loadPost(lines, iStart, lastId) {
  // get level
  var firstLine = lines[iStart];
  var level = getPostLevel(firstLine);
  firstLine = firstLine.substring(level);
  var content = [];
  var signature = loadSignature(firstLine);
  if (signature === null) {
    content.push(firstLine);
  }
  var id = ++lastId;
  
  var nextLevel;
  var line;
  var i = iStart + 1;
  var replies = [];
  while (i < lines.length) {
    line = lines[i];
    nextLevel = getPostLevel(line);
    if (nextLevel > 0 || line.length > 0) {
      if (nextLevel < level) {// new post at higher level
        break;
      } else if (nextLevel > level) {// reply
        var reply = loadPost(lines, i, lastId);
        if (reply.post.isValid()) {
          replies.push(reply.post);
        }
        i = reply.iEnd;
        lastId = reply.lastId;
      }
    }
    if (nextLevel == level) {// post at same level: signature determines
      if (signature !== null) {// new post at same level
        break;
      } else {// still current post: add and search for signature
        line = line.substring(level);
        signature = loadSignature(line);
        if (signature === null) {// signature not found: add content
          content.push(line);
        }
        i += 1;
      }
    } else if (nextLevel === 0 && line.length === 0) {// ignore blank line
      i += 1;
    }
  }
  if (signature !== null) {// signature found: add signature line content if any
    if (signature.content !== null) {
      content.push(signature.content);
    }
    signature = signature.value;
  }
  
  return {
    'post': Post(id, level, content, replies, signature),
    'iEnd': i,
    'lastId': lastId
  };
}

/**
 * Loads a signature object from a wikitext signature.
 * @param {String} line ending with a wikitext signature
 * @return {Object} o with
 * * {String} o.content: text in line before signature
 * * {Object} o.value: signature object
 */
function loadSignature(line) {
  var pos = line.indexOf('--[[');
  if (pos != -1) {
    var content = null;
    if (pos > 0) {
      content = line.substring(0, pos);
    }
    var value = line.substring(pos);
    
    // parse timestamp
    var vcopy = value.substring(4);
    var sNamespaceUser = AddinMooc_CONFIG.get('MW_NAMESPACE_USER') + ':';
    var sPageContributions = AddinMooc_CONFIG.get('MW_PAGE_CONTRIBUTIONS') + '/';
    var username = null;
    
    if (vcopy.substring(0, sNamespaceUser.length) == sNamespaceUser) {// user signature e.g. "--[[Benutzer:Sebschlicht|Sebschlicht]] ([[Benutzer Diskussion:Sebschlicht|Diskussion]]) 10:20, 12. Nov. 2014 (CET)"
      var patternUsername = new RegExp('[^\|]*');
      username = vcopy.match(patternUsername);
      if (username !== null) {
        username = username[0];
        username = username.substring(sNamespaceUser.length);
      }
    } else if (vcopy.substring(0, sPageContributions.length) == sPageContributions) {// IP signature e.g. "--[[Special:Contributions/81.17.28.58|81.17.28.58]] ([[User talk:81.17.28.58|discuss]]) 10:00, 23 October 2013 (UTC)"
      var patternIpAddress = new RegExp('[^\|]*');
      username = vcopy.match(patternIpAddress);
      if (username !== null) {
        username = username[0];
        username = username.substring(sPageContributions.length);
        console.log('IP address: ' + username);
      }
    }
    
    if (username != null) {
      var sTimestamp = vcopy.match(/\).+?$/);
      if (sTimestamp == null) {
      	console.log('german IP signature');
        sTimestamp = vcopy.match(/\].+?$/);
        if (sTimestamp !== null) {
          sTimestamp[0] = sTimestamp[0].substring(1);
        }
      }
      if (sTimestamp !== null) {
        sTimestamp = sTimestamp[0];
        sTimestamp = sTimestamp.substring(2);
        var timestamp = parseTimestamp(sTimestamp);
        console.log('timestamp: ' + timestamp);
        
        return {//TODO: create and use constructor
          'content': content,
          'value': {
            'author': username,
            'timestamp': timestamp,
            'tostring': function() {
              return AddinMooc_CONFIG.message('UI_POST_SIGNATURE', dateToString(this.timestamp), this.author);
            },
            'towikitext': function() {
              return value;
            }
          }
        };
      }
    }
  }
  // unsigned or malformed signature
  return null;
}

/**
 * Loads a thread. Separates thread content from replies.
 * @param {Object} thread instance t with all text belonging to the thread in t.content
 * @param {int} highest used post identifier
 * @return {Object} o with
 * * {int} o.lastId: highest used post identifier
 * * {Object} o.value: thread instance loaded
 */
function loadThread(thread, lastId) {
  var lines = thread.content;
  var content = [];
  var i = 0;
  var signature = null;
  var id = ++lastId;
  while (i < lines.length) {
    var level = getPostLevel(lines[i]);
    if (level > 0) {// post
      var post = loadPost(lines, i, lastId);
      i = post.iEnd;
      lastId = post.lastId;
      if (post.post.isValid()) {
        thread.replies.push(post.post);
      } else {//copy invalid posts to thread's lost section
        thread.lost.push(post.post.content);
        AddinMooc_CONFIG.log(0, 'LOG_DIS_POST_INVALID', post.post.id, post.post.content);
      }
    } else {// thread content
      if (signature === null) {// no signature found yet
        signature = loadSignature(lines[i]);
        if (signature === null) {// no signature found: add content
          content.push(lines[i]);
        } else if (signature.content !== null) {// signature found: add signature line content if any
          content.push(signature.content);
        }
      } else {// signature already found: malformed?
        content.push(lines[i]);
      }
      i += 1;
    }
  }
  // remove trailing newlines
  if (content[0] == '') {
    content.shift();
  }
  if (content[content.length - 1] == '') {
    content.pop();
  }
  thread.id = id;
  thread.content = content.join('\n');
  if (signature !== null) {
    thread.signature = signature.value;
    thread.published = thread.signature.timestamp.getTime();
  }
  return {
    'lastId': lastId,
    'value': thread
  };
}

/**
 * Loads all threads of a talk page.
 * @param {Array<String>} talk page content
 * @param {int} highest used post identifier
 * @return {Object} o with
 * * {int} o.lastId: highest used post identifier
 * * {Array<String>} o.lost
 * * {Array<Object>} o.threads: threads the talk page contained
 */
function loadThreads(lines, lastId) {
  var rawThreads = splitThreads(lines);
  var threads = [];
  // load threads with their replies
  for (var i = 0; i < rawThreads.threads.length; ++i) {
    var thread = loadThread(rawThreads.threads[i], lastId);
    lastId = thread.lastId;
    threads.push(thread.value);
  }
  return {
    'lastId': lastId,
    'lost': rawThreads.lost,
    'threads': threads
  };
}

/**
 * Loads the threads of a number of talk pages into a discussion instance.
 * @param {Array<String>} titles of the talk pages to load
 * @param {int} index within passed titles of the talk page to load
 * @param {Object} discussion instance to push the threads to
 * @param {function} finish callback
 */
function mergeThreads(talkPageTitles, iCrrPage, discussion, callback) {
  if (iCrrPage < talkPageTitles.length) {
    var talkPage = TalkPage(talkPageTitles[iCrrPage]);
    doPageContentRequest(talkPage.title, null, function(pageContent) {
      var lines = splitLines(pageContent);
      var parsed = loadThreads(lines, discussion.lastId);
      var pageThreads = parsed.threads;
      for (var t = 0; t < pageThreads.length; ++t) {
        var pageThread = pageThreads[t];
        pageThread.talkPage = talkPage;
        talkPage.threads.push(pageThread);
        discussion.talkPages.push(talkPage);
        discussion.threads.push(pageThread);
      }
      discussion.lastId = parsed.lastId;
      mergeThreads(talkPageTitles, iCrrPage + 1, discussion, callback);
    }, function() {// failed to retrieve talk page, assume it just does not exist and go on
      mergeThreads(talkPageTitles, iCrrPage + 1, discussion, callback);
    });
  } else {
    callback();
  }
}

/**
 * Loads threads from talk pages, injects them into discussion section and enables global on-page discussion.
 * @param {Array<String>} titles of the talk pages to load from
 */
function renderThreads(talkPageTitles) {
  mergeThreads(talkPageTitles, 0, discussion, function() {
    // insert ask question UI
    insertAskQuestionUI('learningGoals', talkPageTitles[0], false);
    insertAskQuestionUI('video', talkPageTitles[0], true);
    insertAskQuestionUI('script', talkPageTitles[1], true);
    insertAskQuestionUI('quiz', talkPageTitles[2], true);
    insertAskQuestionUI('furtherReading', talkPageTitles[0], false);
    insertAskQuestionUI('discussion', talkPageTitles[0], true);
    
    var threads = discussion.threads;
    AddinMooc_CONFIG.log(0, 'LOG_DIS_NUMTHREADS', threads.length, talkPageTitles);
    threads.sort(function(t1, t2) {// sort threads by timestamp of publication (DESC)
      if (t1.published > t2.published) {
        return -1;
      } else if (t1.published < t2.published) {
        return 1;
      } else {
        return 0;
      }
    });
    
    var injectThreads = function() {
      var divDiscussion = $('#discussion > .content');
      for (var j = 0; j < threads.length; ++j) {
        var nThread = renderThread(threads[j]);
        divDiscussion.append(nThread);
        if (threads.length > 2) {// collapse threads if too many
          collapseThread(nThread, threads[j]);
        }
        
        if (!threads[j].isValid()) {// thread invalid: unsigned
          // TODO show warning(s) below threads
        }
        if (threads[j].lost.length > 0) {// contains invalid posts: unsigned
          // TODO show warning(s) below threads
        }
      }
    };
    
    // parse thread content and inject threads
    var getContentNodes = function(post) {
      var nodes = [];
      nodes.push($('<div>', {'id':post.id}).html(post.content));
      for (var i = 0; i < post.replies.length; ++i) {
        nodes = nodes.concat(getContentNodes(post.replies[i]));
      }
      return nodes;
    };
    var nContent = $('<div>');
    for (var i = 0; i < threads.length; ++i) {
      var nodes = getContentNodes(threads[i]);
      for (var n = 0; n < nodes.length; ++n) {
        nContent.append(nodes[n]);
      }
    }
    parseThreads(nContent.html(), function(parsedContent) {
      var nThreads = $.parseHTML(parsedContent);
      var adoptContentNodes = function(post, nodes, iCrr) {
        post.htmlContent = nodes[iCrr].html();
        iCrr += 1;
        for (var i = 0; i < post.replies.length; ++i) {
          iCrr = adoptContentNodes(post.replies[i], nodes, iCrr);
        }
        return iCrr;
      };
      var nodes = [];
      $.each(nThreads, function(i, el) {
        var nThread = $(el);
        if (typeof nThread.attr('id') !== 'undefined') {
          nodes.push($(el));
        }
      });
      var iCrr = 0;
      for (var i = 0; i < threads.length; ++i) {
        iCrr = adoptContentNodes(threads[i], nodes, iCrr);
      }
      injectThreads();
    }, function() {// inject unparsed threads if parsing failed
      injectThreads();
    });
  });
}

/**
 * Splits a talk page into its single threads.
 * @param {Array<String>} talk page content
 * @return {Object} o with
 * * {Array<Object>} o.threads: thread objects
 * * {int} o.lost: index of last line in root section (thus not belonging to any threads)
 */
function splitThreads(lines) {
  var threads = [];
  var thread = null;
  var level = 0, line, iLost = -1, section = 0;
  for (var i = 0; i < lines.length; ++i) {
    line = lines[i];
    level = getLevel(line);
    if (level > 0) {
      if (level == 2) {// new thread
        if (thread !== null) {// store current
          threads.push(thread);
        }
        thread = Thread(line.substring(level, line.length - level), ++section);
        AddinMooc_CONFIG.log(0, 'LOG_DIS_THREAD_SECTION', line, section);
      } else {
        // malformed: header at invalid level
        section += 1;
        thread.content.push(line);
      }
    } else {// thread content
      if (thread !== null) {
        thread.content.push(line);
      } else {// content not belonging to any threads
        iLost = i;
      }
    }
  }
  if (thread !== null) {// store thread finished by EOF
    threads.push(thread);
  }
  
  return {
    'threads': threads,
    'lost': iLost
  };
}

/*####################
  # UTILITIES
  # low-level helper functions
  ####################*/
 
/**
 * Repeats a string value a given number of times.
 * @param {String} value to repeat
 * @param {int} number of times to repeat the value
 * @return {String} value repeated the given number of times.
 */
function strrep(value, numRepeat) {
	return new Array(numRepeat + 1).join(value);
}

/**
 * Splits a text into its single lines.
 * @param {String} multiline text
 * @return {Array} single text lines
 */
function splitLines(text) {
  return text.split(/\r?\n/);
}

/**
 * Parses a Date object to a String.
 * @param {Date} Date to be parsed
 * @return {String} String representing the date passed (YYYY/MM/dd HH:mm)
 */
function dateToString(date) {
  return (date.getYear() + 1900) + "/" + date.getMonth() + "/" + date.getDate() + " " + date.getHours() + ":" + date.getMinutes();
}

/**
 * Calculates the header level of a wikitext line.
 * @param {String} wikitext line
 * @return {int} header level of the line passed, 0 if the line is no header
 */
function getLevel(line) {
  var sLevelStart = line.match('^=*');
  if (sLevelStart.length > 0 && sLevelStart[0]) {
    var sLevelEnd = line.match('=*$');
    if (sLevelEnd.length > 0 && sLevelEnd[0]) {
      return Math.min(sLevelStart[0].length, sLevelEnd[0].length);
    }
  }
  return 0;
}

/**
 * Converts month name to index.
 * @param {String} month name
 * @return {int} month index starting with 1; -1 if month name unknown
 * @see http://stackoverflow.com/questions/13566552/easiest-way-to-convert-month-name-to-month-number-in-js-jan-01
 */
function getMonthFromString(mon){
  // en: "August"
  // de: "Nov."
  var month = mon.replace('.', '');
  var d = Date.parse(month + "1, 2014");
  if (!isNaN(d)){
    return new Date(d).getMonth() + 1;
  }
  return -1;
}

/**
 * Calculates the post level of a talk page line.
 * @param {String} talk page line
 * @return {int} post level of the line passed (0: no post, n: reply level n)
 */
function getPostLevel(line) {
  var level = line.match('^:*');
  if (level.length > 0 && level[0]) {// post reply
    return level[0].length;
  }
  return 0;
}

/**
 * Calculates the length of the signature in a post.
 * @param {String} post content
 * @return {int} length of the signature; 0 if post is unsigned
 */
function getSignatureLength(content) {
  if (content.match(/\~\~\~\~$/) !== null) {
    if ((content.match(/\-\-\~\~\~\~$/) !== null)) {
      return 6;
    }
    return 4;
  }
  return 0;
}

/**
 * Returns the talk page title of a wiki page.
 * @param {String} title of the wiki page
 * @return {String} title of the talk page of the wiki page specified
 */
function getTalkPageTitle(pageTitle) {
  var iNamespace = pageTitle.indexOf(':');
  var iSlash = pageTitle.indexOf('/');
  var sNamespaceTalk = AddinMooc_CONFIG.get('MW_NAMESPACE_TALK');
  if (iNamespace == -1 || iNamespace > iSlash) {
    return sNamespaceTalk + ':' + pageTitle;
  }
  var namespace = pageTitle.substring(0, iNamespace);
  return namespace + ' ' + sNamespaceTalk.toLowerCase() + pageTitle.substring(iNamespace);
}

/**
 * Strips a post text from any unwanted characters.
 * 1.) manual intendation
 * 2.) additional thread titles
 * 3.) manual signature
 * 4.) leading/trailing whitespace
 * @param {String} post content
 * @return {String} stripped post content
 */
function stripPost(content) {
  var lines = splitLines(content);
  var line, postLevel, level;
  for (var i = 0; i < lines.length; ++i) {
    line = lines[i];
    // strip leading ':'
    postLevel = getPostLevel(line);
    if (postLevel > 0) {
      line = line.substring(postLevel);
    }
    // remove thread starts
    level = getLevel(line);
    if (level > 0) {
      line = line.substring(level, line.length - level);
    }
    lines[i] = line;
  }
  var post = lines.join('\n');
  // remove signature added manually
  var signatureLength = getSignatureLength(post);
  if (signatureLength > 0) {
    post = post.substring(0, post.length - signatureLength);
  }
  // remove leading/trailing whitespace
  return $.trim(post);
}

/**
 * Parses a wiki timestamp to a Date object.
 * @param {String} timestamp in wiki format
 * @return {Date} Date object representing the given timestamp; null if parsing failed
 */
function parseTimestamp(value) {
  var time = value.substring(0, 5);
  var timeParts = time.split(':');
  var day = value.substring(7);
  var dayParts = day.split(' ');
  var date = new Date();
  var month = getMonthFromString(dayParts[1]);
  // en: "13:05, 28 August 2014 (UTC)"
  // de: "10:20, 12. Nov. 2014 (CET)"
  if (month != -1) {
    day = dayParts[0].replace('.', '');
    date.setUTCDate(day);
    date.setUTCMonth(month);
    date.setUTCFullYear(dayParts[2]);
    date.setUTCHours(timeParts[0]);
    date.setUTCMinutes(timeParts[1]);
    return date;
  }
  return null;
}

//</nowiki>