window.KTUtilElementDataStore = {};
window.KTUtilElementDataStoreID = 0;
window.KTUtilDelegatedEventHandlers = {};

var KTUtil = function() {
    var resizeHandlers = [];

    /**
     * Handle window resize event with some
     * delay to attach event handlers upon resize complete
     */
    var _windowResizeHandler = function() {
        var _runResizeHandlers = function() {
            // reinitialize other subscribed elements
            for (var i = 0; i < resizeHandlers.length; i++) {
                var each = resizeHandlers[i];
                each.call();
            }
        };

        var timer;

        window.addEventListener('resize', function() {
            KTUtil.throttle(timer, function() {
                _runResizeHandlers();
            }, 200);
        });
    };

    return {
        /**
         * Class main initializer.
         * @param {object} settings.
         * @returns null
         */
        //main function to initiate the theme
        init: function(settings) {
            _windowResizeHandler();
        },

        /**
         * Adds window resize event handler.
         * @param {function} callback function.
         */
        addResizeHandler: function(callback) {
            resizeHandlers.push(callback);
        },

        /**
         * Removes window resize event handler.
         * @param {function} callback function.
         */
        removeResizeHandler: function(callback) {
            for (var i = 0; i < resizeHandlers.length; i++) {
                if (callback === resizeHandlers[i]) {
                    delete resizeHandlers[i];
                }
            }
        },

        /**
         * Trigger window resize handlers.
         */
        runResizeHandlers: function() {
            _runResizeHandlers();
        },

        resize: function() {
            if (typeof(Event) === 'function') {
                // modern browsers
                window.dispatchEvent(new Event('resize'));
            } else {
                // for IE and other old browsers
                // causes deprecation warning on modern browsers
                var evt = window.document.createEvent('UIEvents');
                evt.initUIEvent('resize', true, false, window, 0);
                window.dispatchEvent(evt);
            }
        },

        /**
         * Get GET parameter value from URL.
         * @param {string} paramName Parameter name.
         * @returns {string}
         */
        getURLParam: function(paramName) {
            var searchString = window.location.search.substring(1),
                i, val, params = searchString.split("&");

            for (i = 0; i < params.length; i++) {
                val = params[i].split("=");
                if (val[0] == paramName) {
                    return unescape(val[1]);
                }
            }

            return null;
        },

        /**
         * Checks whether current device is mobile touch.
         * @returns {boolean}
         */
        isMobileDevice: function() {
            var test = (this.getViewPort().width < this.getBreakpoint('lg') ? true : false);

            if (test === false) {
                // For use within normal web clients
                test = navigator.userAgent.match(/iPad/i) != null;
            }

            return test;
        },

        /**
         * Checks whether current device is desktop.
         * @returns {boolean}
         */
        isDesktopDevice: function() {
            return KTUtil.isMobileDevice() ? false : true;
        },

        /**
         * Gets browser window viewport size. Ref:
         * http://andylangton.co.uk/articles/javascript/get-viewport-size-javascript/
         * @returns {object}
         */
        getViewPort: function() {
            var e = window,
                a = 'inner';
            if (!('innerWidth' in window)) {
                a = 'client';
                e = document.documentElement || document.body;
            }

            return {
                width: e[a + 'Width'],
                height: e[a + 'Height']
            };
        },

        /**
         * Checks whether given device mode is currently activated.
         * @param {string} mode Responsive mode name(e.g: desktop,
         *     desktop-and-tablet, tablet, tablet-and-mobile, mobile)
         * @returns {boolean}
         */
        isBreakpointUp: function(mode) {
            var width = this.getViewPort().width;
            var breakpoint = this.getBreakpoint(mode);

            return (width >= breakpoint);
        },

        isBreakpointDown: function(mode) {
            var width = this.getViewPort().width;
            var breakpoint = this.getBreakpoint(mode);

            return (width < breakpoint);
        },

        getViewportWidth: function() {
            return this.getViewPort().width;
        },

        /**
         * Generates unique ID for give prefix.
         * @param {string} prefix Prefix for generated ID
         * @returns {boolean}
         */
        getUniqueId: function(prefix) {
            return prefix + Math.floor(Math.random() * (new Date()).getTime());
        },

        /**
         * Gets window width for give breakpoint mode.
         * @param {string} mode Responsive mode name(e.g: xl, lg, md, sm)
         * @returns {number}
         */
        getBreakpoint: function(breakpoint) {
            var value = this.getCssVariableValue('--bs-' + breakpoint);

            if ( value ) {
                value = parseInt(value.trim());
            }

            return value;
        },

        /**
         * Checks whether object has property matchs given key path.
         * @param {object} obj Object contains values paired with given key path
         * @param {string} keys Keys path seperated with dots
         * @returns {object}
         */
        isset: function(obj, keys) {
            var stone;

            keys = keys || '';

            if (keys.indexOf('[') !== -1) {
                throw new Error('Unsupported object path notation.');
            }

            keys = keys.split('.');

            do {
                if (obj === undefined) {
                    return false;
                }

                stone = keys.shift();

                if (!obj.hasOwnProperty(stone)) {
                    return false;
                }

                obj = obj[stone];

            } while (keys.length);

            return true;
        },

        /**
         * Gets highest z-index of the given element parents
         * @param {object} el jQuery element object
         * @returns {number}
         */
        getHighestZindex: function(el) {
            var position, value;

            while (el && el !== document) {
                // Ignore z-index if position is set to a value where z-index is ignored by the browser
                // This makes behavior of this function consistent across browsers
                // WebKit always returns auto if the element is positioned
                position = KTUtil.css(el, 'position');

                if (position === "absolute" || position === "relative" || position === "fixed") {
                    // IE returns 0 when zIndex is not specified
                    // other browsers return a string
                    // we ignore the case of nested elements with an explicit value of 0
                    // <div style="z-index: -10;"><div style="z-index: 0;"></div></div>
                    value = parseInt(KTUtil.css(el, 'z-index'));

                    if (!isNaN(value) && value !== 0) {
                        return value;
                    }
                }

                el = el.parentNode;
            }

            return 1;
        },

        /**
         * Checks whether the element has any parent with fixed positionfreg
         * @param {object} el jQuery element object
         * @returns {boolean}
         */
        hasFixedPositionedParent: function(el) {
            var position;

            while (el && el !== document) {
                position = KTUtil.css(el, 'position');

                if (position === "fixed") {
                    return true;
                }

                el = el.parentNode;
            }

            return false;
        },

        /**
         * Simulates delay
         */
        sleep: function(milliseconds) {
            var start = new Date().getTime();
            for (var i = 0; i < 1e7; i++) {
                if ((new Date().getTime() - start) > milliseconds) {
                    break;
                }
            }
        },

        /**
         * Gets randomly generated integer value within given min and max range
         * @param {number} min Range start value
         * @param {number} max Range end value
         * @returns {number}
         */
        getRandomInt: function(min, max) {
            return Math.floor(Math.random() * (max - min + 1)) + min;
        },

        /**
         * Checks whether Angular library is included
         * @returns {boolean}
         */
        isAngularVersion: function() {
            return window.Zone !== undefined ? true : false;
        },

        // Deep extend:  $.extend(true, {}, objA, objB);
        deepExtend: function(out) {
            out = out || {};

            for (var i = 1; i < arguments.length; i++) {
                var obj = arguments[i];
                if (!obj) continue;

                for (var key in obj) {
                    if (!obj.hasOwnProperty(key)) {
                        continue;
                    }

                    // based on https://javascriptweblog.wordpress.com/2011/08/08/fixing-the-javascript-typeof-operator/
                    if ( Object.prototype.toString.call(obj[key]) === '[object Object]' ) {
                        out[key] = KTUtil.deepExtend(out[key], obj[key]);
                        continue;
                    }

                    out[key] = obj[key];
                }
            }

            return out;
        },

        // extend:  $.extend({}, objA, objB);
        extend: function(out) {
            out = out || {};

            for (var i = 1; i < arguments.length; i++) {
                if (!arguments[i])
                    continue;

                for (var key in arguments[i]) {
                    if (arguments[i].hasOwnProperty(key))
                        out[key] = arguments[i][key];
                }
            }

            return out;
        },

        getBody: function() {
            return document.getElementsByTagName('body')[0];
        },

        /**
         * Checks whether the element has given classes
         * @param {object} el jQuery element object
         * @param {string} Classes string
         * @returns {boolean}
         */
        hasClasses: function(el, classes) {
            if (!el) {
                return;
            }

            var classesArr = classes.split(" ");

            for (var i = 0; i < classesArr.length; i++) {
                if (KTUtil.hasClass(el, KTUtil.trim(classesArr[i])) == false) {
                    return false;
                }
            }

            return true;
        },

        hasClass: function(el, className) {
            if (!el) {
                return;
            }

            return el.classList ? el.classList.contains(className) : new RegExp('\\b' + className + '\\b').test(el.className);
        },

        addClass: function(el, className) {
            if (!el || typeof className === 'undefined') {
                return;
            }

            var classNames = className.split(' ');

            if (el.classList) {
                for (var i = 0; i < classNames.length; i++) {
                    if (classNames[i] && classNames[i].length > 0) {
                        el.classList.add(KTUtil.trim(classNames[i]));
                    }
                }
            } else if (!KTUtil.hasClass(el, className)) {
                for (var x = 0; x < classNames.length; x++) {
                    el.className += ' ' + KTUtil.trim(classNames[x]);
                }
            }
        },

        removeClass: function(el, className) {
            if (!el || typeof className === 'undefined') {
                return;
            }

            var classNames = className.split(' ');

            if (el.classList) {
                for (var i = 0; i < classNames.length; i++) {
                    el.classList.remove(KTUtil.trim(classNames[i]));
                }
            } else if (KTUtil.hasClass(el, className)) {
                for (var x = 0; x < classNames.length; x++) {
                    el.className = el.className.replace(new RegExp('\\b' + KTUtil.trim(classNames[x]) + '\\b', 'g'), '');
                }
            }
        },

        triggerCustomEvent: function(el, eventName, data) {
            var event;
            if (window.CustomEvent) {
                event = new CustomEvent(eventName, {
                    detail: data
                });
            } else {
                event = document.createEvent('CustomEvent');
                event.initCustomEvent(eventName, true, true, data);
            }

            el.dispatchEvent(event);
        },

        triggerEvent: function(node, eventName) {
            // Make sure we use the ownerDocument from the provided node to avoid cross-window problems
            var doc;

            if (node.ownerDocument) {
                doc = node.ownerDocument;
            } else if (node.nodeType == 9) {
                // the node may be the document itself, nodeType 9 = DOCUMENT_NODE
                doc = node;
            } else {
                throw new Error("Invalid node passed to fireEvent: " + node.id);
            }

            if (node.dispatchEvent) {
                // Gecko-style approach (now the standard) takes more work
                var eventClass = "";

                // Different events have different event classes.
                // If this switch statement can't map an eventName to an eventClass,
                // the event firing is going to fail.
                switch (eventName) {
                    case "click": // Dispatching of 'click' appears to not work correctly in Safari. Use 'mousedown' or 'mouseup' instead.
                    case "mouseenter":
                    case "mouseleave":
                    case "mousedown":
                    case "mouseup":
                        eventClass = "MouseEvents";
                        break;

                    case "focus":
                    case "change":
                    case "blur":
                    case "select":
                        eventClass = "HTMLEvents";
                        break;

                    default:
                        throw "fireEvent: Couldn't find an event class for event '" + eventName + "'.";
                        break;
                }
                var event = doc.createEvent(eventClass);

                var bubbles = eventName == "change" ? false : true;
                event.initEvent(eventName, bubbles, true); // All events created as bubbling and cancelable.

                event.synthetic = true; // allow detection of synthetic events
                // The second parameter says go ahead with the default action
                node.dispatchEvent(event, true);
            } else if (node.fireEvent) {
                // IE-old school style
                var event = doc.createEventObject();
                event.synthetic = true; // allow detection of synthetic events
                node.fireEvent("on" + eventName, event);
            }
        },

        index: function( el ){
            var c = el.parentNode.children, i = 0;
            for(; i < c.length; i++ )
                if( c[i] == el ) return i;
        },

        trim: function(string) {
            return string.trim();
        },

        eventTriggered: function(e) {
            if (e.currentTarget.dataset.triggered) {
                return true;
            } else {
                e.currentTarget.dataset.triggered = true;

                return false;
            }
        },

        remove: function(el) {
            if (el && el.parentNode) {
                el.parentNode.removeChild(el);
            }
        },

        find: function(parent, query) {
            if ( parent !== null) {
                return parent.querySelector(query);
            } else {
                return null;
            }
        },

        findAll: function(parent, query) {
            if ( parent !== null ) {
                return parent.querySelectorAll(query);
            } else {
                return null;
            }
        },

        insertAfter: function(el, referenceNode) {
            return referenceNode.parentNode.insertBefore(el, referenceNode.nextSibling);
        },

        parents: function(elem, selector) {
            // Set up a parent array
            var parents = [];

            // Push each parent element to the array
            for ( ; elem && elem !== document; elem = elem.parentNode ) {
                if (selector) {
                    if (elem.matches(selector)) {
                        parents.push(elem);
                    }
                    continue;
                }
                parents.push(elem);
            }

            // Return our parent array
            return parents;
        },

        children: function(el, selector, log) {
            if (!el || !el.childNodes) {
                return null;
            }

            var result = [],
                i = 0,
                l = el.childNodes.length;

            for (var i; i < l; ++i) {
                if (el.childNodes[i].nodeType == 1 && KTUtil.matches(el.childNodes[i], selector, log)) {
                    result.push(el.childNodes[i]);
                }
            }

            return result;
        },

        child: function(el, selector, log) {
            var children = KTUtil.children(el, selector, log);

            return children ? children[0] : null;
        },

        matches: function(el, selector, log) {
            var p = Element.prototype;
            var f = p.matches || p.webkitMatchesSelector || p.mozMatchesSelector || p.msMatchesSelector || function(s) {
                return [].indexOf.call(document.querySelectorAll(s), this) !== -1;
            };

            if (el && el.tagName) {
                return f.call(el, selector);
            } else {
                return false;
            }
        },

        data: function(el) {
            return {
                set: function(name, data) {
                    if (!el) {
                        return;
                    }

                    if (el.customDataTag === undefined) {
                        window.KTUtilElementDataStoreID++;
                        el.customDataTag = window.KTUtilElementDataStoreID;
                    }

                    if (window.KTUtilElementDataStore[el.customDataTag] === undefined) {
                        window.KTUtilElementDataStore[el.customDataTag] = {};
                    }

                    window.KTUtilElementDataStore[el.customDataTag][name] = data;
                },

                get: function(name) {
                    if (!el) {
                        return;
                    }

                    if (el.customDataTag === undefined) {
                        return null;
                    }

                    return this.has(name) ? window.KTUtilElementDataStore[el.customDataTag][name] : null;
                },

                has: function(name) {
                    if (!el) {
                        return false;
                    }

                    if (el.customDataTag === undefined) {
                        return false;
                    }

                    return (window.KTUtilElementDataStore[el.customDataTag] && window.KTUtilElementDataStore[el.customDataTag][name]) ? true : false;
                },

                remove: function(name) {
                    if (el && this.has(name)) {
                        delete window.KTUtilElementDataStore[el.customDataTag][name];
                    }
                }
            };
        },

        outerWidth: function(el, margin) {
            var width;

            if (margin === true) {
                width = parseFloat(el.offsetWidth);
                width += parseFloat(KTUtil.css(el, 'margin-left')) + parseFloat(KTUtil.css(el, 'margin-right'));

                return parseFloat(width);
            } else {
                width = parseFloat(el.offsetWidth);

                return width;
            }
        },

        offset: function(el) {
            var rect, win;

            if ( !el ) {
                return;
            }

            // Return zeros for disconnected and hidden (display: none) elements (gh-2310)
            // Support: IE <=11 only
            // Running getBoundingClientRect on a
            // disconnected node in IE throws an error

            if ( !el.getClientRects().length ) {
                return { top: 0, left: 0 };
            }

            // Get document-relative position by adding viewport scroll to viewport-relative gBCR
            rect = el.getBoundingClientRect();
            win = el.ownerDocument.defaultView;

            return {
                top: rect.top + win.pageYOffset,
                left: rect.left + win.pageXOffset,
                right: window.innerWidth - (el.offsetLeft + el.offsetWidth)
            };
        },

        height: function(el) {
            return KTUtil.css(el, 'height');
        },

        outerHeight: function(el, withMargin) {
            var height = el.offsetHeight;
            var style;

            if (typeof withMargin !== 'undefined' && withMargin === true) {
                style = getComputedStyle(el);
                height += parseInt(style.marginTop) + parseInt(style.marginBottom);

                return height;
            } else {
                return height;
            }
        },

        visible: function(el) {
            return !(el.offsetWidth === 0 && el.offsetHeight === 0);
        },

        attr: function(el, name, value) {
            if (el == undefined) {
                return;
            }

            if (value !== undefined) {
                el.setAttribute(name, value);
            } else {
                return el.getAttribute(name);
            }
        },

        hasAttr: function(el, name) {
            if (el == undefined) {
                return;
            }

            return el.getAttribute(name) ? true : false;
        },

        removeAttr: function(el, name) {
            if (el == undefined) {
                return;
            }

            el.removeAttribute(name);
        },

        animate: function(from, to, duration, update, easing, done) {
            /**
             * TinyAnimate.easings
             *  Adapted from jQuery Easing
             */
            var easings = {};
            var easing;

            easings.linear = function(t, b, c, d) {
                return c * t / d + b;
            };

            easing = easings.linear;

            // Early bail out if called incorrectly
            if (typeof from !== 'number' ||
                typeof to !== 'number' ||
                typeof duration !== 'number' ||
                typeof update !== 'function') {
                return;
            }

            // Create mock done() function if necessary
            if (typeof done !== 'function') {
                done = function() {};
            }

            // Pick implementation (requestAnimationFrame | setTimeout)
            var rAF = window.requestAnimationFrame || function(callback) {
                window.setTimeout(callback, 1000 / 50);
            };

            // Animation loop
            var canceled = false;
            var change = to - from;

            function loop(timestamp) {
                var time = (timestamp || +new Date()) - start;

                if (time >= 0) {
                    update(easing(time, from, change, duration));
                }
                if (time >= 0 && time >= duration) {
                    update(to);
                    done();
                } else {
                    rAF(loop);
                }
            }

            update(from);

            // Start animation loop
            var start = window.performance && window.performance.now ? window.performance.now() : +new Date();

            rAF(loop);
        },

        actualCss: function(el, prop, cache) {
            var css = '';

            if (el instanceof HTMLElement === false) {
                return;
            }

            if (!el.getAttribute('kt-hidden-' + prop) || cache === false) {
                var value;

                // the element is hidden so:
                // making the el block so we can meassure its height but still be hidden
                css = el.style.cssText;
                el.style.cssText = 'position: absolute; visibility: hidden; display: block;';

                if (prop == 'width') {
                    value = el.offsetWidth;
                } else if (prop == 'height') {
                    value = el.offsetHeight;
                }

                el.style.cssText = css;

                // store it in cache
                el.setAttribute('kt-hidden-' + prop, value);

                return parseFloat(value);
            } else {
                // store it in cache
                return parseFloat(el.getAttribute('kt-hidden-' + prop));
            }
        },

        actualHeight: function(el, cache) {
            return KTUtil.actualCss(el, 'height', cache);
        },

        actualWidth: function(el, cache) {
            return KTUtil.actualCss(el, 'width', cache);
        },

        getScroll: function(element, method) {
            // The passed in `method` value should be 'Top' or 'Left'
            method = 'scroll' + method;
            return (element == window || element == document) ? (
                self[(method == 'scrollTop') ? 'pageYOffset' : 'pageXOffset'] ||
                (browserSupportsBoxModel && document.documentElement[method]) ||
                document.body[method]
            ) : element[method];
        },

        css: function(el, styleProp, value, important) {
            if (!el) {
                return;
            }

            if (value !== undefined) {
                if ( important === true ) {
                    el.style.setProperty(styleProp, value, 'important');
                } else {
                    el.style[styleProp] = value;
                }
            } else {
                var defaultView = (el.ownerDocument || document).defaultView;

                // W3C standard way:
                if (defaultView && defaultView.getComputedStyle) {
                    // sanitize property name to css notation
                    // (hyphen separated words eg. font-Size)
                    styleProp = styleProp.replace(/([A-Z])/g, "-$1").toLowerCase();

                    return defaultView.getComputedStyle(el, null).getPropertyValue(styleProp);
                } else if (el.currentStyle) { // IE
                    // sanitize property name to camelCase
                    styleProp = styleProp.replace(/\-(\w)/g, function(str, letter) {
                        return letter.toUpperCase();
                    });

                    value = el.currentStyle[styleProp];

                    // convert other units to pixels on IE
                    if (/^\d+(em|pt|%|ex)?$/i.test(value)) {
                        return (function(value) {
                            var oldLeft = el.style.left, oldRsLeft = el.runtimeStyle.left;

                            el.runtimeStyle.left = el.currentStyle.left;
                            el.style.left = value || 0;
                            value = el.style.pixelLeft + "px";
                            el.style.left = oldLeft;
                            el.runtimeStyle.left = oldRsLeft;

                            return value;
                        })(value);
                    }

                    return value;
                }
            }
        },

        slide: function(el, dir, speed, callback, recalcMaxHeight) {
            if (!el || (dir == 'up' && KTUtil.visible(el) === false) || (dir == 'down' && KTUtil.visible(el) === true)) {
                return;
            }

            speed = (speed ? speed : 600);
            var calcHeight = KTUtil.actualHeight(el);
            var calcPaddingTop = false;
            var calcPaddingBottom = false;

            if (KTUtil.css(el, 'padding-top') && KTUtil.data(el).has('slide-padding-top') !== true) {
                KTUtil.data(el).set('slide-padding-top', KTUtil.css(el, 'padding-top'));
            }

            if (KTUtil.css(el, 'padding-bottom') && KTUtil.data(el).has('slide-padding-bottom') !== true) {
                KTUtil.data(el).set('slide-padding-bottom', KTUtil.css(el, 'padding-bottom'));
            }

            if (KTUtil.data(el).has('slide-padding-top')) {
                calcPaddingTop = parseInt(KTUtil.data(el).get('slide-padding-top'));
            }

            if (KTUtil.data(el).has('slide-padding-bottom')) {
                calcPaddingBottom = parseInt(KTUtil.data(el).get('slide-padding-bottom'));
            }

            if (dir == 'up') { // up
                el.style.cssText = 'display: block; overflow: hidden;';

                if (calcPaddingTop) {
                    KTUtil.animate(0, calcPaddingTop, speed, function(value) {
                        el.style.paddingTop = (calcPaddingTop - value) + 'px';
                    }, 'linear');
                }

                if (calcPaddingBottom) {
                    KTUtil.animate(0, calcPaddingBottom, speed, function(value) {
                        el.style.paddingBottom = (calcPaddingBottom - value) + 'px';
                    }, 'linear');
                }

                KTUtil.animate(0, calcHeight, speed, function(value) {
                    el.style.height = (calcHeight - value) + 'px';
                }, 'linear', function() {
                    el.style.height = '';
                    el.style.display = 'none';

                    if (typeof callback === 'function') {
                        callback();
                    }
                });


            } else if (dir == 'down') { // down
                el.style.cssText = 'display: block; overflow: hidden;';

                if (calcPaddingTop) {
                    KTUtil.animate(0, calcPaddingTop, speed, function(value) {//
                        el.style.paddingTop = value + 'px';
                    }, 'linear', function() {
                        el.style.paddingTop = '';
                    });
                }

                if (calcPaddingBottom) {
                    KTUtil.animate(0, calcPaddingBottom, speed, function(value) {
                        el.style.paddingBottom = value + 'px';
                    }, 'linear', function() {
                        el.style.paddingBottom = '';
                    });
                }

                KTUtil.animate(0, calcHeight, speed, function(value) {
                    el.style.height = value + 'px';
                }, 'linear', function() {
                    el.style.height = '';
                    el.style.display = '';
                    el.style.overflow = '';

                    if (typeof callback === 'function') {
                        callback();
                    }
                });
            }
        },

        slideUp: function(el, speed, callback) {
            KTUtil.slide(el, 'up', speed, callback);
        },

        slideDown: function(el, speed, callback) {
            KTUtil.slide(el, 'down', speed, callback);
        },

        show: function(el, display) {
            if (typeof el !== 'undefined') {
                el.style.display = (display ? display : 'block');
            }
        },

        hide: function(el) {
            if (typeof el !== 'undefined') {
                el.style.display = 'none';
            }
        },

        addEvent: function(el, type, handler, one) {
            if (typeof el !== 'undefined' && el !== null) {
                el.addEventListener(type, handler);
            }
        },

        removeEvent: function(el, type, handler) {
            if (el !== null) {
                el.removeEventListener(type, handler);
            }
        },

        on: function(element, selector, event, handler) {
            if ( element === null ) {
                return;
            }

            var eventId = KTUtil.getUniqueId('event');

            window.KTUtilDelegatedEventHandlers[eventId] = function(e) {
                var targets = element.querySelectorAll(selector);
                var target = e.target;

                while ( target && target !== element ) {
                    for ( var i = 0, j = targets.length; i < j; i++ ) {
                        if ( target === targets[i] ) {
                            handler.call(target, e);
                        }
                    }

                    target = target.parentNode;
                }
            }

            KTUtil.addEvent(element, event, window.KTUtilDelegatedEventHandlers[eventId]);

            return eventId;
        },

        off: function(element, event, eventId) {
            if (!element || !window.KTUtilDelegatedEventHandlers[eventId]) {
                return;
            }

            KTUtil.removeEvent(element, event, window.KTUtilDelegatedEventHandlers[eventId]);

            delete window.KTUtilDelegatedEventHandlers[eventId];
        },

        one: function onetime(el, type, callback) {
            el.addEventListener(type, function callee(e) {
                // remove event
                if (e.target && e.target.removeEventListener) {
                    e.target.removeEventListener(e.type, callee);
                }

                // need to verify from https://themeforest.net/author_dashboard#comment_23615588
                if (el && el.removeEventListener) {
                    e.currentTarget.removeEventListener(e.type, callee);
                }

                // call handler
                return callback(e);
            });
        },

        hash: function(str) {
            var hash = 0,
                i, chr;

            if (str.length === 0) return hash;
            for (i = 0; i < str.length; i++) {
                chr = str.charCodeAt(i);
                hash = ((hash << 5) - hash) + chr;
                hash |= 0; // Convert to 32bit integer
            }

            return hash;
        },

        animateClass: function(el, animationName, callback) {
            var animation;
            var animations = {
                animation: 'animationend',
                OAnimation: 'oAnimationEnd',
                MozAnimation: 'mozAnimationEnd',
                WebkitAnimation: 'webkitAnimationEnd',
                msAnimation: 'msAnimationEnd',
            };

            for (var t in animations) {
                if (el.style[t] !== undefined) {
                    animation = animations[t];
                }
            }

            KTUtil.addClass(el, animationName);

            KTUtil.one(el, animation, function() {
                KTUtil.removeClass(el, animationName);
            });

            if (callback) {
                KTUtil.one(el, animation, callback);
            }
        },

        transitionEnd: function(el, callback) {
            var transition;
            var transitions = {
                transition: 'transitionend',
                OTransition: 'oTransitionEnd',
                MozTransition: 'mozTransitionEnd',
                WebkitTransition: 'webkitTransitionEnd',
                msTransition: 'msTransitionEnd'
            };

            for (var t in transitions) {
                if (el.style[t] !== undefined) {
                    transition = transitions[t];
                }
            }

            KTUtil.one(el, transition, callback);
        },

        animationEnd: function(el, callback) {
            var animation;
            var animations = {
                animation: 'animationend',
                OAnimation: 'oAnimationEnd',
                MozAnimation: 'mozAnimationEnd',
                WebkitAnimation: 'webkitAnimationEnd',
                msAnimation: 'msAnimationEnd'
            };

            for (var t in animations) {
                if (el.style[t] !== undefined) {
                    animation = animations[t];
                }
            }

            KTUtil.one(el, animation, callback);
        },

        animateDelay: function(el, value) {
            var vendors = ['webkit-', 'moz-', 'ms-', 'o-', ''];
            for (var i = 0; i < vendors.length; i++) {
                KTUtil.css(el, vendors[i] + 'animation-delay', value);
            }
        },

        animateDuration: function(el, value) {
            var vendors = ['webkit-', 'moz-', 'ms-', 'o-', ''];
            for (var i = 0; i < vendors.length; i++) {
                KTUtil.css(el, vendors[i] + 'animation-duration', value);
            }
        },

        scrollTo: function(target, offset, duration) {
            var duration = duration ? duration : 500;
            var targetPos = target ? KTUtil.offset(target).top : 0;
            var scrollPos = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
            var from, to;

            if (offset) {
                targetPos = targetPos - offset;
            }

            from = scrollPos;
            to = targetPos;

            KTUtil.animate(from, to, duration, function(value) {
                document.documentElement.scrollTop = value;
                document.body.parentNode.scrollTop = value;
                document.body.scrollTop = value;
            }); //, easing, done
        },

        scrollTop: function(offset, duration) {
            KTUtil.scrollTo(null, offset, duration);
        },

        isArray: function(obj) {
            return obj && Array.isArray(obj);
        },

        isEmpty: function(obj) {
            for (var prop in obj) {
                if (obj.hasOwnProperty(prop)) {
                    return false;
                }
            }

            return true;
        },

        numberString: function(nStr) {
            nStr += '';
            var x = nStr.split('.');
            var x1 = x[0];
            var x2 = x.length > 1 ? '.' + x[1] : '';
            var rgx = /(\d+)(\d{3})/;
            while (rgx.test(x1)) {
                x1 = x1.replace(rgx, '$1' + ',' + '$2');
            }
            return x1 + x2;
        },

        isRTL: function() {
            return (document.querySelector('html').getAttribute("direction") === 'rtl');
        },

        snakeToCamel: function(s){
            return s.replace(/(\-\w)/g, function(m){return m[1].toUpperCase();});
        },

        filterBoolean: function(val) {
            // Convert string boolean
            if (val === true || val === 'true') {
                return true;
            }

            if (val === false || val === 'false') {
                return false;
            }

            return val;
        },

        setHTML: function(el, html) {
            el.innerHTML = html;
        },

        getHTML: function(el) {
            if (el) {
                return el.innerHTML;
            }
        },

        getDocumentHeight: function() {
            var body = document.body;
            var html = document.documentElement;

            return Math.max( body.scrollHeight, body.offsetHeight, html.clientHeight, html.scrollHeight, html.offsetHeight );
        },

        getScrollTop: function() {
            return  (document.scrollingElement || document.documentElement).scrollTop;
        },

        colorLighten: function(color, amount) {
            const addLight = function(color, amount){
                let cc = parseInt(color,16) + amount;
                let c = (cc > 255) ? 255 : (cc);
                c = (c.toString(16).length > 1 ) ? c.toString(16) : `0${c.toString(16)}`;
                return c;
            }

            color = (color.indexOf("#")>=0) ? color.substring(1,color.length) : color;
            amount = parseInt((255*amount)/100);

            return color = `#${addLight(color.substring(0,2), amount)}${addLight(color.substring(2,4), amount)}${addLight(color.substring(4,6), amount)}`;
        },

        colorDarken: function(color, amount) {
            const subtractLight = function(color, amount){
                let cc = parseInt(color,16) - amount;
                let c = (cc < 0) ? 0 : (cc);
                c = (c.toString(16).length > 1 ) ? c.toString(16) : `0${c.toString(16)}`;

                return c;
            }

            color = (color.indexOf("#")>=0) ? color.substring(1,color.length) : color;
            amount = parseInt((255*amount)/100);

            return color = `#${subtractLight(color.substring(0,2), amount)}${subtractLight(color.substring(2,4), amount)}${subtractLight(color.substring(4,6), amount)}`;
        },

        // Throttle function: Input as function which needs to be throttled and delay is the time interval in milliseconds
        throttle:  function (timer, func, delay) {
            // If setTimeout is already scheduled, no need to do anything
            if (timer) {
                return;
            }

            // Schedule a setTimeout after delay seconds
            timer  =  setTimeout(function () {
                func();

                // Once setTimeout function execution is finished, timerId = undefined so that in <br>
                // the next scroll event function execution can be scheduled by the setTimeout
                timer  =  undefined;
            }, delay);
        },

        // Debounce function: Input as function which needs to be debounced and delay is the debounced time in milliseconds
        debounce: function (timer, func, delay) {
            // Cancels the setTimeout method execution
            clearTimeout(timer)

            // Executes the func after delay time.
            timer  =  setTimeout(func, delay);
        },

        parseJson: function(value) {
            if (typeof value === 'string') {
                value = value.replace(/'/g, "\"");

                var jsonStr = value.replace(/(\w+:)|(\w+ :)/g, function(matched) {
                    return '"' + matched.substring(0, matched.length - 1) + '":';
                });

                try {
                    value = JSON.parse(jsonStr);
                } catch(e) { }
            }

            return value;
        },

        getResponsiveValue: function(value, defaultValue) {
            var width = this.getViewPort().width;
            var result;

            value = KTUtil.parseJson(value);

            if (typeof value === 'object') {
                var resultKey;
                var resultBreakpoint = -1;
                var breakpoint;

                for (var key in value) {
                    if (key === 'default') {
                        breakpoint = 0;
                    } else {
                        breakpoint = this.getBreakpoint(key) ? this.getBreakpoint(key) : parseInt(key);
                    }

                    if (breakpoint <= width && breakpoint > resultBreakpoint) {
                        resultKey = key;
                        resultBreakpoint = breakpoint;
                    }
                }

                if (resultKey) {
                    result = value[resultKey];
                } else {
                    result = value;
                }
            } else {
                result = value;
            }

            return result;
        },

        each: function(array, callback) {
            return [].slice.call(array).map(callback);
        },

        getSelectorMatchValue: function(value) {
            var result = null;
            value = KTUtil.parseJson(value);

            if ( typeof value === 'object' ) {
                // Match condition
                if ( value['match'] !== undefined ) {
                    var selector = Object.keys(value['match'])[0];
                    value = Object.values(value['match'])[0];

                    if ( document.querySelector(selector) !== null ) {
                        result = value;
                    }
                }
            } else {
                result = value;
            }

            return result;
        },

        getConditionalValue: function(value) {
            var value = KTUtil.parseJson(value);
            var result = KTUtil.getResponsiveValue(value);

            if ( result !== null && result['match'] !== undefined ) {
                result = KTUtil.getSelectorMatchValue(result);
            }

            if ( result === null && value !== null && value['default'] !== undefined ) {
                result = value['default'];
            }

            return result;
        },

        getCssVariableValue: function(variableName) {
            var hex = getComputedStyle(document.documentElement).getPropertyValue(variableName);
            if ( hex && hex.length > 0 ) {
                hex = hex.trim();
            }

            return hex;
        },

        isInViewport: function(element) {
            var rect = element.getBoundingClientRect();

            return (
                rect.top >= 0 &&
                rect.left >= 0 &&
                rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
                rect.right <= (window.innerWidth || document.documentElement.clientWidth)
            );
        },

        onDOMContentLoaded: function(callback) {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', callback);
            } else {
                callback();
            }
        },

        inIframe: function() {
            try {
                return window.self !== window.top;
            } catch (e) {
                return true;
            }
        }
    }
}();

var KTEventHandler = function() {
    ////////////////////////////
    // ** Private Variables  ** //
    ////////////////////////////
    var _handlers = {};

    ////////////////////////////
    // ** Private Methods  ** //
    ////////////////////////////
    var _triggerEvent = function(element, name, target, e) {
        if ( KTUtil.data(element).has(name) === true ) {
            var handlerId = KTUtil.data(element).get(name);

            if ( _handlers[name] && _handlers[name][handlerId] ) {
                var handler = _handlers[name][handlerId];

                if ( handler.name === name ) {
                    if ( handler.one == true ) {
                        if ( handler.fired == false ) {
                            _handlers[name][handlerId].fired = true;

                            return handler.callback.call(this, target, e);
                        }
                    } else {
                        return handler.callback.call(this, target, e);
                    }
                }
            }
        }

        return null;
    }

    var _addEvent = function(element, name, callback, one) {
        var handlerId = KTUtil.getUniqueId('event');

        KTUtil.data(element).set(name, handlerId);

        if ( !_handlers[name] ) {
            _handlers[name] = {};
        }

        _handlers[name][handlerId] = {
            name: name,
            callback: callback,
            one: one,
            fired: false
        };
    }

    var _removeEvent = function(element, name) {
        var handlerId = KTUtil.data(element).get(name);

        if (_handlers[name] && _handlers[name][handlerId]) {
            delete _handlers[name][handlerId];
        }
    }

    ////////////////////////////
    // ** Public Methods  ** //
    ////////////////////////////
    return {
        trigger: function(element, name, target, e) {
            return _triggerEvent(element, name, target, e);
        },

        on: function(element, name, handler) {
            return _addEvent(element, name, handler);
        },

        one: function(element, name, handler) {
            return _addEvent(element, name, handler, true);
        },

        off: function(element, name) {
            return _removeEvent(element, name);
        },

        debug: function() {
            for (var b in _handlers) {
                if ( _handlers.hasOwnProperty(b) ) console.log(b);
            }
        }
    }
}();


// Class definition
var KTMenu = function (element, options) {
    ////////////////////////////
    // ** Private Variables  ** //
    ////////////////////////////
    var the = this;

    if (typeof element === "undefined" || element === null) {
        return;
    }

    // Default Options
    var defaultOptions = {
        dropdown: {
            hoverTimeout: 200,
            zindex: 105
        },

        accordion: {
            slideSpeed: 250,
            expand: false
        }
    };

    ////////////////////////////
    // ** Private Methods  ** //
    ////////////////////////////

    var _construct = function () {
        if (KTUtil.data(element).has('menu') === true) {
            the = KTUtil.data(element).get('menu');
        } else {
            _init();
        }
    }

    var _init = function () {
        the.options = KTUtil.deepExtend({}, defaultOptions, options);
        the.uid = KTUtil.getUniqueId('menu');
        the.element = element;
        the.triggerElement;

        // Set initialized
        the.element.setAttribute('data-kt-menu', 'true');

        _setTriggerElement();
        _update();

        KTUtil.data(the.element).set('menu', the);
    }

    var _destroy = function () {  // todo

    }

    // Event Handlers
    // Toggle handler
    var _click = function (element, e) {
        e.preventDefault();

        var item = _getItemElement(element);

        if (_getItemOption(item, 'trigger') !== 'click') {
            return;
        }

        if (_getItemOption(item, 'toggle') === false) {
            _show(item);
        } else {
            _toggle(item);
        }
    }

    // Link handler
    var _link = function (element, e) {
        if (KTEventHandler.trigger(the.element, 'kt.menu.link.click', the) === false) {
            return;
        }

        // Dismiss all shown dropdowns
        KTMenu.hideDropdowns();

        KTEventHandler.trigger(the.element, 'kt.menu.link.clicked', the);
    }

    // Dismiss handler
    var _dismiss = function (element, e) {
        var item = _getItemElement(element);
        var items = _getItemChildElements(item);

        if (item !== null && _getItemSubType(item) === 'dropdown') {
            _hide(item); // hide items dropdown
            // Hide all child elements as well

            if (items.length > 0) {
                for (var i = 0, len = items.length; i < len; i++) {
                    if (items[i] !== null && _getItemSubType(items[i]) === 'dropdown') {
                        _hide(tems[i]);
                    }
                }
            }
        }
    }

    // Mouseover handle
    var _mouseover = function (element, e) {
        var item = _getItemElement(element);

        if (item === null) {
            return;
        }

        if (_getItemOption(item, 'trigger') !== 'hover') {
            return;
        }

        if (KTUtil.data(item).get('hover') === '1') {
            clearTimeout(KTUtil.data(item).get('timeout'));
            KTUtil.data(item).remove('hover');
            KTUtil.data(item).remove('timeout');
        }

        _show(item);
    }

    // Mouseout handle
    var _mouseout = function (element, e) {
        var item = _getItemElement(element);

        if (item === null) {
            return;
        }

        if (_getItemOption(item, 'trigger') !== 'hover') {
            return;
        }

        var timeout = setTimeout(function () {
            if (KTUtil.data(item).get('hover') === '1') {
                _hide(item);
            }
        }, the.options.dropdown.hoverTimeout);

        KTUtil.data(item).set('hover', '1');
        KTUtil.data(item).set('timeout', timeout);
    }

    // Toggle item sub
    var _toggle = function (item) {
        if (!item) {
            item = the.triggerElement;
        }

        if (_isItemSubShown(item) === true) {
            _hide(item);
        } else {
            _show(item);
        }
    }

    // Show item sub
    var _show = function (item) {
        if (!item) {
            item = the.triggerElement;
        }

        if (_isItemSubShown(item) === true) {
            return;
        }

        if (_getItemSubType(item) === 'dropdown') {
            _showDropdown(item); // // show current dropdown
        } else if (_getItemSubType(item) === 'accordion') {
            _showAccordion(item);
        }

        // Remember last submenu type
        KTUtil.data(item).set('type', _getItemSubType(item));  // updated
    }

    // Hide item sub
    var _hide = function (item) {
        if (!item) {
            item = the.triggerElement;
        }

        if (_isItemSubShown(item) === false) {
            return;
        }

        if (_getItemSubType(item) === 'dropdown') {
            _hideDropdown(item);
        } else if (_getItemSubType(item) === 'accordion') {
            _hideAccordion(item);
        }
    }

    // Reset item state classes if item sub type changed
    var _reset = function (item) {
        if (_hasItemSub(item) === false) {
            return;
        }

        var sub = _getItemSubElement(item);

        // Reset sub state if sub type is changed during the window resize
        if (KTUtil.data(item).has('type') && KTUtil.data(item).get('type') !== _getItemSubType(item)) {  // updated
            KTUtil.removeClass(item, 'hover');
            KTUtil.removeClass(item, 'show');
            KTUtil.removeClass(sub, 'show');
        }  // updated
    }

    // Update all item state classes if item sub type changed
    var _update = function () {
        var items = the.element.querySelectorAll('.menu-item[data-kt-menu-trigger]');

        if (items && items.length > 0) {
            for (var i = 0, len = items.length; i < len; i++) {
                _reset(items[i]);
            }
        }
    }

    // Set external trigger element
    var _setTriggerElement = function () {
        var target = document.querySelector('[data-kt-menu-target="# ' + the.element.getAttribute('id') + '"]');

        if (target !== null) {
            the.triggerElement = target;
        } else if (the.element.closest('[data-kt-menu-trigger]')) {
            the.triggerElement = the.element.closest('[data-kt-menu-trigger]');
        } else if (the.element.parentNode && KTUtil.child(the.element.parentNode, '[data-kt-menu-trigger]')) {
            the.triggerElement = KTUtil.child(the.element.parentNode, '[data-kt-menu-trigger]');
        }

        if (the.triggerElement) {
            KTUtil.data(the.triggerElement).set('menu', the);
        }
    }

    // Test if menu has external trigger element
    var _isTriggerElement = function (item) {
        return (the.triggerElement === item) ? true : false;
    }

    // Test if item's sub is shown
    var _isItemSubShown = function (item) {
        var sub = _getItemSubElement(item);

        if (sub !== null) {
            if (_getItemSubType(item) === 'dropdown') {
                if (KTUtil.hasClass(sub, 'show') === true && sub.hasAttribute('data-popper-placement') === true) {
                    return true;
                } else {
                    return false;
                }
            } else {
                return KTUtil.hasClass(item, 'show');
            }
        } else {
            return false;
        }
    }

    // Test if item dropdown is permanent
    var _isItemDropdownPermanent = function (item) {
        return _getItemOption(item, 'permanent') === true ? true : false;
    }

    // Test if item's parent is shown
    var _isItemParentShown = function (item) {
        return KTUtil.parents(item, '.menu-item.show').length > 0;
    }

    // Test of it is item sub element
    var _isItemSubElement = function (item) {
        return KTUtil.hasClass(item, 'menu-sub');
    }

    // Test if item has sub
    var _hasItemSub = function (item) {
        return (KTUtil.hasClass(item, 'menu-item') && item.hasAttribute('data-kt-menu-trigger'));
    }

    // Get link element
    var _getItemLinkElement = function (item) {
        return KTUtil.child(item, '.menu-link');
    }

    // Get toggle element
    var _getItemToggleElement = function (item) {
        if (the.triggerElement) {
            return the.triggerElement;
        } else {
            return _getItemLinkElement(item);
        }
    }

    // Get item sub element
    var _getItemSubElement = function (item) {
        if (_isTriggerElement(item) === true) {
            return the.element;
        }
        if (item.classList.contains('menu-sub') === true) {
            return item;
        } else if (KTUtil.data(item).has('sub')) {
            return KTUtil.data(item).get('sub');
        } else {
            return KTUtil.child(item, '.menu-sub');
        }
    }

    // Get item sub type
    var _getItemSubType = function (element) {
        var sub = _getItemSubElement(element);

        if (sub && parseInt(KTUtil.css(sub, 'z-index')) > 0) {
            return "dropdown";
        } else {
            return "accordion";
        }
    }

    // Get item element
    var _getItemElement = function (element) {
        var item, sub;

        // Element is the external trigger element
        if (_isTriggerElement(element)) {
            return element;
        }

        // Element has item toggler attribute
        if (element.hasAttribute('data-kt-menu-trigger')) {
            return element;
        }

        // Element has item DOM reference in it's data storage
        if (KTUtil.data(element).has('item')) {
            return KTUtil.data(element).get('item');
        }

        // Item is parent of element
        if ((item = element.closest('.menu-item[data-kt-menu-trigger]'))) {
            return item;
        }

        // Element's parent has item DOM reference in it's data storage
        if ((sub = element.closest('.menu-sub'))) {
            if (KTUtil.data(sub).has('item') === true) {
                return KTUtil.data(sub).get('item')
            }
        }
    }

    // Get item parent element
    var _getItemParentElement = function (item) {
        var sub = item.closest('.menu-sub');
        var parentItem;

        if (KTUtil.data(sub).has('item')) {
            return KTUtil.data(sub).get('item');
        }

        if (sub && (parentItem = sub.closest('.menu-item[data-kt-menu-trigger]'))) {
            return parentItem;
        }

        return null;
    }

    // Get item parent elements
    var _getItemParentElements = function (item) {
        var parents = [];
        var parent;
        var i = 0;

        do {
            parent = _getItemParentElement(item);

            if (parent) {
                parents.push(parent);
                item = parent;
            }

            i++;
        } while (parent !== null && i < 20);

        if (the.triggerElement) {
            parents.unshift(the.triggerElement);
        }

        return parents;
    }

    // Get item child element
    var _getItemChildElement = function (item) {
        var selector = item;
        var element;

        if (KTUtil.data(item).get('sub')) {
            selector = KTUtil.data(item).get('sub');
        }

        if (selector !== null) {
            //element = selector.querySelector('.show.menu-item[data-kt-menu-trigger]');
            element = selector.querySelector('.menu-item[data-kt-menu-trigger]');

            if (element) {
                return element;
            } else {
                return null;
            }
        } else {
            return null;
        }
    }

    // Get item child elements
    var _getItemChildElements = function (item) {
        var children = [];
        var child;
        var i = 0;

        do {
            child = _getItemChildElement(item);

            if (child) {
                children.push(child);
                item = child;
            }

            i++;
        } while (child !== null && i < 20);

        return children;
    }

    // Show item dropdown
    var _showDropdown = function (item) {
        // Handle dropdown show event
        if (KTEventHandler.trigger(the.element, 'kt.menu.dropdown.show', item) === false) {
            return;
        }

        // Hide all currently shown dropdowns except current one
        KTMenu.hideDropdowns(item);

        var toggle = _isTriggerElement(item) ? item : _getItemLinkElement(item);
        var sub = _getItemSubElement(item);

        var width = _getItemOption(item, 'width');
        var height = _getItemOption(item, 'height');

        var zindex = the.options.dropdown.zindex; // update
        var parentZindex = KTUtil.getHighestZindex(item); // update

        // Apply a new z-index if dropdown's toggle element or it's parent has greater z-index // update
        if (parentZindex !== null && parentZindex >= zindex) {
            zindex = parentZindex + 1;
        }

        if (zindex > 0) {
            KTUtil.css(sub, 'z-index', zindex);
        }

        if (width !== null) {
            KTUtil.css(sub, 'width', width);
        }

        if (height !== null) {
            KTUtil.css(sub, 'height', height);
        }

        KTUtil.css(sub, 'display', '');
        KTUtil.css(sub, 'overflow', '');

        // Init popper(new)
        _initDropdownPopper(item, sub);

        KTUtil.addClass(item, 'show');
        KTUtil.addClass(item, 'menu-dropdown');
        KTUtil.addClass(sub, 'show');

        // Append the sub the the root of the menu
        if (_getItemOption(item, 'overflow') === true) {
            document.body.appendChild(sub);
            KTUtil.data(item).set('sub', sub);
            KTUtil.data(sub).set('item', item);
            KTUtil.data(sub).set('menu', the);
        } else {
            KTUtil.data(sub).set('item', item);
        }

        // Handle dropdown shown event
        KTEventHandler.trigger(the.element, 'kt.menu.dropdown.shown', item);
    }

    // Hide item dropdown
    var _hideDropdown = function (item) {
        // Handle dropdown hide event
        if (KTEventHandler.trigger(the.element, 'kt.menu.dropdown.hide', item) === false) {
            return;
        }

        var sub = _getItemSubElement(item);

        KTUtil.css(sub, 'z-index', '');
        KTUtil.css(sub, 'width', '');
        KTUtil.css(sub, 'height', '');

        KTUtil.removeClass(item, 'show');
        KTUtil.removeClass(item, 'menu-dropdown');
        KTUtil.removeClass(sub, 'show');

        // Append the sub back to it's parent
        if (_getItemOption(item, 'overflow') === true) {
            if (item.classList.contains('menu-item')) {
                item.appendChild(sub);
            } else {
                KTUtil.insertAfter(the.element, item);
            }

            KTUtil.data(item).remove('sub');
            KTUtil.data(sub).remove('item');
            KTUtil.data(sub).remove('menu');
        }

        // Destroy popper(new)
        _destroyDropdownPopper(item);

        // Handle dropdown hidden event
        KTEventHandler.trigger(the.element, 'kt.menu.dropdown.hidden', item);
    }

    // Init dropdown popper(new)
    var _initDropdownPopper = function (item, sub) {
        // Setup popper instance
        var reference;
        var attach = _getItemOption(item, 'attach');

        if (attach) {
            if (attach === 'parent') {
                reference = item.parentNode;
            } else {
                reference = document.querySelector(attach);
            }
        } else {
            reference = item;
        }

        var popper = Popper.createPopper(reference, sub, _getDropdownPopperConfig(item));
        KTUtil.data(item).set('popper', popper);
    }

    // Destroy dropdown popper(new)
    var _destroyDropdownPopper = function (item) {
        if (KTUtil.data(item).has('popper') === true) {
            KTUtil.data(item).get('popper').destroy();
            KTUtil.data(item).remove('popper');
        }
    }

    // Prepare popper config for dropdown(see: https://popper.js.org/docs/v2/)
    var _getDropdownPopperConfig = function (item) {
        // Placement
        var placement = _getItemOption(item, 'placement');
        if (!placement) {
            placement = 'right';
        }

        // Offset
        var offsetValue = _getItemOption(item, 'offset');
        var offset = offsetValue ? offsetValue.split(",") : [];

        // Strategy
        var strategy = _getItemOption(item, 'overflow') === true ? 'absolute' : 'fixed';

        var altAxis = _getItemOption(item, 'flip') !== false ? true : false;

        var popperConfig = {
            placement: placement,
            strategy: strategy,
            modifiers: [{
                name: 'offset',
                options: {
                    offset: offset
                }
            }, {
                name: 'preventOverflow',
                options: {
                    altAxis: altAxis
                }
            }, {
                name: 'flip',
                options: {
                    flipVariations: false
                }
            }]
        };

        return popperConfig;
    }

    // Show item accordion
    var _showAccordion = function (item) {
        if (KTEventHandler.trigger(the.element, 'kt.menu.accordion.show', item) === false) {
            return;
        }

        if (the.options.accordion.expand === false) {
            _hideAccordions(item);
        }

        var sub = _getItemSubElement(item);

        if (KTUtil.data(item).has('popper') === true) {
            _hideDropdown(item);
        }

        KTUtil.addClass(item, 'hover'); // updateWW

        KTUtil.addClass(item, 'showing');

        KTUtil.slideDown(sub, the.options.accordion.slideSpeed, function () {
            KTUtil.removeClass(item, 'showing');
            KTUtil.addClass(item, 'show');
            KTUtil.addClass(sub, 'show');

            KTEventHandler.trigger(the.element, 'kt.menu.accordion.shown', item);
        });
    }

    // Hide item accordion
    var _hideAccordion = function (item) {
        if (KTEventHandler.trigger(the.element, 'kt.menu.accordion.hide', item) === false) {
            return;
        }

        var sub = _getItemSubElement(item);

        KTUtil.addClass(item, 'hiding');

        KTUtil.slideUp(sub, the.options.accordion.slideSpeed, function () {
            KTUtil.removeClass(item, 'hiding');
            KTUtil.removeClass(item, 'show');
            KTUtil.removeClass(sub, 'show');

            KTUtil.removeClass(item, 'hover'); // update

            KTEventHandler.trigger(the.element, 'kt.menu.accordion.hidden', item);
        });
    }

    // Hide all shown accordions of item
    var _hideAccordions = function (item) {
        var itemsToHide = KTUtil.findAll(the.element, '.show[data-kt-menu-trigger]');
        var itemToHide;

        if (itemsToHide && itemsToHide.length > 0) {
            for (var i = 0, len = itemsToHide.length; i < len; i++) {
                itemToHide = itemsToHide[i];

                if (_getItemSubType(itemToHide) === 'accordion' && itemToHide !== item && item.contains(itemToHide) === false && itemToHide.contains(item) === false) {
                    _hideAccordion(itemToHide);
                }
            }
        }
    }

    // Get item option(through html attributes)
    var _getItemOption = function (item, name) {
        var attr;
        var value = null;

        if (item && item.hasAttribute('data-kt-menu-' + name)) {
            attr = item.getAttribute('data-kt-menu-' + name);
            value = KTUtil.getResponsiveValue(attr);

            if (value !== null && String(value) === 'true') {
                value = true;
            } else if (value !== null && String(value) === 'false') {
                value = false;
            }
        }

        return value;
    }

    var _destroy = function () {
        KTUtil.data(the.element).remove('menu');
    }

    // Construct Class
    _construct();

    ///////////////////////
    // ** Public API  ** //
    ///////////////////////

    // Event Handlers
    the.click = function (element, e) {
        return _click(element, e);
    }

    the.link = function (element, e) {
        return _link(element, e);
    }

    the.dismiss = function (element, e) {
        return _dismiss(element, e);
    }

    the.mouseover = function (element, e) {
        return _mouseover(element, e);
    }

    the.mouseout = function (element, e) {
        return _mouseout(element, e);
    }

    // General Methods
    the.getItemTriggerType = function (item) {
        return _getItemOption(item, 'trigger');
    }

    the.getItemSubType = function (element) {
        return _getItemSubType(element);
    }

    the.show = function (item) {
        return _show(item);
    }

    the.hide = function (item) {
        return _hide(item);
    }

    the.reset = function (item) {
        return _reset(item);
    }

    the.update = function () {
        return _update();
    }

    the.getElement = function () {
        return the.element;
    }

    the.getItemLinkElement = function (item) {
        return _getItemLinkElement(item);
    }

    the.getItemToggleElement = function (item) {
        return _getItemToggleElement(item);
    }

    the.getItemSubElement = function (item) {
        return _getItemSubElement(item);
    }

    the.getItemParentElements = function (item) {
        return _getItemParentElements(item);
    }

    the.isItemSubShown = function (item) {
        return _isItemSubShown(item);
    }

    the.isItemParentShown = function (item) {
        return _isItemParentShown(item);
    }

    the.getTriggerElement = function () {
        return the.triggerElement;
    }

    the.isItemDropdownPermanent = function (item) {
        return _isItemDropdownPermanent(item);
    }

    the.destroy = function () {
        return _destroy();
    }

    // Accordion Mode Methods
    the.hideAccordions = function (item) {
        return _hideAccordions(item);
    }

    // Event API
    the.on = function (name, handler) {
        return KTEventHandler.on(the.element, name, handler);
    }

    the.one = function (name, handler) {
        return KTEventHandler.one(the.element, name, handler);
    }

    the.off = function (name) {
        return KTEventHandler.off(the.element, name);
    }
};

// Get KTMenu instance by element
KTMenu.getInstance = function (element) {
    var menu;
    var item;

    // Element has menu DOM reference in it's DATA storage
    if (KTUtil.data(element).has('menu')) {
        return KTUtil.data(element).get('menu');
    }

    // Element has .menu parent
    if (menu = element.closest('.menu')) {
        if (KTUtil.data(menu).has('menu')) {
            return KTUtil.data(menu).get('menu');
        }
    }

    // Element has a parent with DOM reference to .menu in it's DATA storage
    if (KTUtil.hasClass(element, 'menu-link')) {
        var sub = element.closest('.menu-sub');

        if (KTUtil.data(sub).has('menu')) {
            return KTUtil.data(sub).get('menu');
        }
    }

    return null;
}

// Hide all dropdowns and skip one if provided
KTMenu.hideDropdowns = function (skip) {
    var items = document.querySelectorAll('.show.menu-dropdown[data-kt-menu-trigger]');

    if (items && items.length > 0) {
        for (var i = 0, len = items.length; i < len; i++) {
            var item = items[i];
            var menu = KTMenu.getInstance(item);

            if (menu && menu.getItemSubType(item) === 'dropdown') {
                if (skip) {
                    if (menu.getItemSubElement(item).contains(skip) === false && item.contains(skip) === false && item !== skip) {
                        menu.hide(item);
                    }
                } else {
                    menu.hide(item);
                }
            }
        }
    }
}

// Update all dropdowns popover instances
KTMenu.updateDropdowns = function () {
    var items = document.querySelectorAll('.show.menu-dropdown[data-kt-menu-trigger]');

    if (items && items.length > 0) {
        for (var i = 0, len = items.length; i < len; i++) {
            var item = items[i];

            if (KTUtil.data(item).has('popper')) {
                KTUtil.data(item).get('popper').forceUpdate();
            }
        }
    }
}

// Global handlers
KTMenu.initGlobalHandlers = function () {
    // Dropdown handler
    document.addEventListener("click", function (e) {
        var items = document.querySelectorAll('.show.menu-dropdown[data-kt-menu-trigger]');
        var menu;
        var item;
        var sub;
        var menuObj;

        if (items && items.length > 0) {
            for (var i = 0, len = items.length; i < len; i++) {
                item = items[i];
                menuObj = KTMenu.getInstance(item);

                if (menuObj && menuObj.getItemSubType(item) === 'dropdown') {
                    menu = menuObj.getElement();
                    sub = menuObj.getItemSubElement(item);

                    if (item === e.target || item.contains(e.target)) {
                        continue;
                    }

                    if (sub === e.target || sub.contains(e.target)) {
                        continue;
                    }

                    menuObj.hide(item);
                }
            }
        }
    });

    // Sub toggle handler(updated)
    KTUtil.on(document.body, '.menu-item[data-kt-menu-trigger] > .menu-link, [data-kt-menu-trigger]:not(.menu-item):not([data-kt-menu-trigger="auto"])', 'click', function (e) {
        var menu = KTMenu.getInstance(this);

        if (menu !== null) {
            return menu.click(this, e);
        }
    });

    // Link handler
    KTUtil.on(document.body, '.menu-item:not([data-kt-menu-trigger]) > .menu-link', 'click', function (e) {
        var menu = KTMenu.getInstance(this);

        if (menu !== null) {
            return menu.link(this, e);
        }
    });

    // Dismiss handler
    KTUtil.on(document.body, '[data-kt-menu-dismiss="true"]', 'click', function (e) {
        var menu = KTMenu.getInstance(this);

        if (menu !== null) {
            return menu.dismiss(this, e);
        }
    });

    // Mouseover handler
    KTUtil.on(document.body, '[data-kt-menu-trigger], .menu-sub', 'mouseover', function (e) {
        var menu = KTMenu.getInstance(this);

        if (menu !== null && menu.getItemSubType(this) === 'dropdown') {
            return menu.mouseover(this, e);
        }
    });

    // Mouseout handler
    KTUtil.on(document.body, '[data-kt-menu-trigger], .menu-sub', 'mouseout', function (e) {
        var menu = KTMenu.getInstance(this);

        if (menu !== null && menu.getItemSubType(this) === 'dropdown') {
            return menu.mouseout(this, e);
        }
    });

    // Resize handler
    window.addEventListener('resize', function () {
        var menu;
        var timer;

        KTUtil.throttle(timer, function () {
            // Locate and update Offcanvas instances on window resize
            var elements = document.querySelectorAll('[data-kt-menu="true"]');

            if (elements && elements.length > 0) {
                for (var i = 0, len = elements.length; i < len; i++) {
                    menu = KTMenu.getInstance(elements[i]);
                    if (menu) {
                        menu.update();
                    }
                }
            }
        }, 200);
    });
}

// Global instances
KTMenu.createInstances = function (selector = '[data-kt-menu="true"]') {
    // Initialize menus
    var elements = document.querySelectorAll(selector);
    if (elements && elements.length > 0) {
        for (var i = 0, len = elements.length; i < len; i++) {
            new KTMenu(elements[i]);
        }
    }
}

// Global initialization
KTMenu.init = function () {
    // Global Event Handlers
    KTMenu.initGlobalHandlers();

    // Lazy Initialization
    KTMenu.createInstances();
};

// On document ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', KTMenu.init);
} else {
    KTMenu.init();
}



// Class definition
var KTToggle = function(element, options) {
    ////////////////////////////
    // ** Private variables  ** //
    ////////////////////////////
    var the = this;
    var body = document.getElementsByTagName("BODY")[0];

    if (!element) {
        return;
    }

    // Default Options
    var defaultOptions = {
        saveState: true
    };

    ////////////////////////////
    // ** Private methods  ** //
    ////////////////////////////

    var _construct = function() {
        if ( KTUtil.data(element).has('toggle') === true ) {
            the = KTUtil.data(element).get('toggle');
        } else {
            _init();
        }
    }

    var _init = function() {
        // Variables
        the.options = KTUtil.deepExtend({}, defaultOptions, options);
        the.uid = KTUtil.getUniqueId('toggle');

        // Elements
        the.element = element;

        the.target = document.querySelector(the.element.getAttribute('data-kt-toggle-target')) ? document.querySelector(the.element.getAttribute('data-kt-toggle-target')) : the.element;
        the.state = the.element.hasAttribute('data-kt-toggle-state') ? the.element.getAttribute('data-kt-toggle-state') : '';
        the.attribute = 'data-kt-' + the.element.getAttribute('data-kt-toggle-name');

        // Event Handlers
        _handlers();

        // Bind Instance
        KTUtil.data(the.element).set('toggle', the);
    }

    var _handlers = function() {
        KTUtil.addEvent(the.element, 'click', function(e) {
            e.preventDefault();

            _toggle();
        });
    }

    // Event handlers
    var _toggle = function() {
        // Trigger "after.toggle" event
        KTEventHandler.trigger(the.element, 'kt.toggle.change', the);

        if ( _isEnabled() ) {
            _disable();
        } else {
            _enable();
        }

        // Trigger "before.toggle" event
        KTEventHandler.trigger(the.element, 'kt.toggle.changed', the);

        return the;
    }

    var _enable = function() {
        if ( _isEnabled() === true ) {
            return;
        }

        KTEventHandler.trigger(the.element, 'kt.toggle.enable', the);

        the.target.setAttribute(the.attribute, 'on');

        if (the.state.length > 0) {
            the.element.classList.add(the.state);
        }

        if ( typeof KTCookie !== 'undefined' && the.options.saveState === true ) {
            KTCookie.set(the.attribute, 'on');
        }

        KTEventHandler.trigger(the.element, 'kt.toggle.enabled', the);

        return the;
    }

    var _disable = function() {
        if ( _isEnabled() === false ) {
            return;
        }

        KTEventHandler.trigger(the.element, 'kt.toggle.disable', the);

        the.target.removeAttribute(the.attribute);

        if (the.state.length > 0) {
            the.element.classList.remove(the.state);
        }

        if ( typeof KTCookie !== 'undefined' && the.options.saveState === true ) {
            KTCookie.remove(the.attribute);
        }

        KTEventHandler.trigger(the.element, 'kt.toggle.disabled', the);

        return the;
    }

    var _isEnabled = function() {
        return (String(the.target.getAttribute(the.attribute)).toLowerCase() === 'on');
    }

    var _destroy = function() {
        KTUtil.data(the.element).remove('toggle');
    }

    // Construct class
    _construct();

    ///////////////////////
    // ** Public API  ** //
    ///////////////////////

    // Plugin API
    the.toggle = function() {
        return _toggle();
    }

    the.enable = function() {
        return _enable();
    }

    the.disable = function() {
        return _disable();
    }

    the.isEnabled = function() {
        return _isEnabled();
    }

    the.goElement = function() {
        return the.element;
    }

    the.destroy = function() {
        return _destroy();
    }

    // Event API
    the.on = function(name, handler) {
        return KTEventHandler.on(the.element, name, handler);
    }

    the.one = function(name, handler) {
        return KTEventHandler.one(the.element, name, handler);
    }

    the.off = function(name) {
        return KTEventHandler.off(the.element, name);
    }

    the.trigger = function(name, event) {
        return KTEventHandler.trigger(the.element, name, event, the, event);
    }
};

// Static methods
KTToggle.getInstance = function(element) {
    if ( element !== null && KTUtil.data(element).has('toggle') ) {
        return KTUtil.data(element).get('toggle');
    } else {
        return null;
    }
}

// Create instances
KTToggle.createInstances = function(selector = '[data-kt-toggle]') {
    var body = document.getElementsByTagName("BODY")[0];

    // Get instances
    var elements = body.querySelectorAll(selector);

    if ( elements && elements.length > 0 ) {
        for (var i = 0, len = elements.length; i < len; i++) {
            // Initialize instances
            new KTToggle(elements[i]);
        }
    }
}

// Global initialization
KTToggle.init = function() {
    KTToggle.createInstances();
};

// On document ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', KTToggle.init);
} else {
    KTToggle.init();
}


// Class definition
var KTLayoutAside = function () {
    // Private variables
    var toggle;
    var aside;

    // Private functions
    var handleToggle = function () {
        var toggleObj = KTToggle.getInstance(toggle);

        // Add a class to prevent aside hover effect after toggle click
        toggleObj.on('kt.toggle.change', function() {
            aside.classList.add('animating');

            setTimeout(function() {
                aside.classList.remove('animating');
            }, 300);
        })
    }

    // Public methods
    return {
        init: function () {
            // Elements
            aside = document.querySelector('#kt_aside');
            toggle = document.querySelector('#kt_aside_toggle');

            if (!aside || !toggle) {
                return;
            }

            handleToggle();
        }
    };
}();

// On document ready
KTUtil.onDOMContentLoaded(function () {
    KTLayoutAside.init();
});




// Class definition
var KTScroll = function(element, options) {
    ////////////////////////////
    // ** Private Variables  ** //
    ////////////////////////////
    var the = this;
    var body = document.getElementsByTagName("BODY")[0];

    if (!element) {
        return;
    }

    // Default options
    var defaultOptions = {
        saveState: true
    };

    ////////////////////////////
    // ** Private Methods  ** //
    ////////////////////////////

    var _construct = function() {
        if ( KTUtil.data(element).has('scroll') ) {
            the = KTUtil.data(element).get('scroll');
        } else {
            _init();
        }
    }

    var _init = function() {
        // Variables
        the.options = KTUtil.deepExtend({}, defaultOptions, options);

        // Elements
        the.element = element;
        the.id = the.element.getAttribute('id');

        // Set initialized
        the.element.setAttribute('data-kt-scroll', 'true');

        // Update
        _update();

        // Bind Instance
        KTUtil.data(the.element).set('scroll', the);
    }

    var _setupHeight = function() {
        var heightType = _getHeightType();
        var height = _getHeight();

        // Set height
        if ( height !== null && height.length > 0 ) {
            KTUtil.css(the.element, heightType, height);
        } else {
            KTUtil.css(the.element, heightType, '');
        }
    }

    var _setupState = function () {
        if ( _getOption('save-state') === true && typeof KTCookie !== 'undefined' && the.id ) {
            if ( KTCookie.get(the.id + 'st') ) {
                var pos = parseInt(KTCookie.get(the.id + 'st'));

                if ( pos > 0 ) {
                    the.element.scrollTop = pos;
                }
            }
        }
    }

    var _setupScrollHandler = function() {
        if ( _getOption('save-state') === true && typeof KTCookie !== 'undefined' && the.id ) {
            the.element.addEventListener('scroll', _scrollHandler);
        } else {
            the.element.removeEventListener('scroll', _scrollHandler);
        }
    }

    var _destroyScrollHandler = function() {
        the.element.removeEventListener('scroll', _scrollHandler);
    }

    var _resetHeight = function() {
        KTUtil.css(the.element, _getHeightType(), '');
    }

    var _scrollHandler = function () {
        KTCookie.set(the.id + 'st', the.element.scrollTop);
    }

    var _update = function() {
        // Activate/deactivate
        if ( _getOption('activate') === true || the.element.hasAttribute('data-kt-scroll-activate') === false ) {
            _setupHeight();
            _setupScrollHandler();
            _setupState();
        } else {
            _resetHeight()
            _destroyScrollHandler();
        }
    }

    var _getHeight = function() {
        var height = _getOption(_getHeightType());

        if ( height instanceof Function ) {
            return height.call();
        } else if ( height !== null && typeof height === 'string' && height.toLowerCase() === 'auto' ) {
            return _getAutoHeight();
        } else {
            return height;
        }
    }

    var _getAutoHeight = function() {
        var height = KTUtil.getViewPort().height;

        var dependencies = _getOption('dependencies');
        var wrappers = _getOption('wrappers');
        var offset = _getOption('offset');

        // Height dependencies
        if ( dependencies !== null ) {
            var elements = document.querySelectorAll(dependencies);

            if ( elements && elements.length > 0 ) {
                for ( var i = 0, len = elements.length; i < len; i++ ) {
                    var element = elements[i];

                    if ( KTUtil.visible(element) === false ) {
                        continue;
                    }

                    height = height - parseInt(KTUtil.css(element, 'height'));
                    height = height - parseInt(KTUtil.css(element, 'margin-top'));
                    height = height - parseInt(KTUtil.css(element, 'margin-bottom'));

                    if (KTUtil.css(element, 'border-top')) {
                        height = height - parseInt(KTUtil.css(element, 'border-top'));
                    }

                    if (KTUtil.css(element, 'border-bottom')) {
                        height = height - parseInt(KTUtil.css(element, 'border-bottom'));
                    }
                }
            }
        }

        // Wrappers
        if ( wrappers !== null ) {
            var elements = document.querySelectorAll(wrappers);
            if ( elements && elements.length > 0 ) {
                for ( var i = 0, len = elements.length; i < len; i++ ) {
                    var element = elements[i];

                    if ( KTUtil.visible(element) === false ) {
                        continue;
                    }

                    height = height - parseInt(KTUtil.css(element, 'margin-top'));
                    height = height - parseInt(KTUtil.css(element, 'margin-bottom'));
                    height = height - parseInt(KTUtil.css(element, 'padding-top'));
                    height = height - parseInt(KTUtil.css(element, 'padding-bottom'));

                    if (KTUtil.css(element, 'border-top')) {
                        height = height - parseInt(KTUtil.css(element, 'border-top'));
                    }

                    if (KTUtil.css(element, 'border-bottom')) {
                        height = height - parseInt(KTUtil.css(element, 'border-bottom'));
                    }
                }
            }
        }

        // Custom offset
        if ( offset !== null ) {
            height = height - parseInt(offset);
        }

        height = height - parseInt(KTUtil.css(the.element, 'margin-top'));
        height = height - parseInt(KTUtil.css(the.element, 'margin-bottom'));

        if (KTUtil.css(element, 'border-top')) {
            height = height - parseInt(KTUtil.css(element, 'border-top'));
        }

        if (KTUtil.css(element, 'border-bottom')) {
            height = height - parseInt(KTUtil.css(element, 'border-bottom'));
        }

        height = String(height) + 'px';

        return height;
    }

    var _getOption = function(name) {
        if ( the.element.hasAttribute('data-kt-scroll-' + name) === true ) {
            var attr = the.element.getAttribute('data-kt-scroll-' + name);

            var value = KTUtil.getResponsiveValue(attr);

            if ( value !== null && String(value) === 'true' ) {
                value = true;
            } else if ( value !== null && String(value) === 'false' ) {
                value = false;
            }

            return value;
        } else {
            var optionName = KTUtil.snakeToCamel(name);

            if ( the.options[optionName] ) {
                return KTUtil.getResponsiveValue(the.options[optionName]);
            } else {
                return null;
            }
        }
    }

    var _getHeightType = function() {
        if (_getOption('height')) {
            return 'height';
        } if (_getOption('min-height')) {
            return 'min-height';
        } if (_getOption('max-height')) {
            return 'max-height';
        }
    }

    var _destroy = function() {
        KTUtil.data(the.element).remove('scroll');
    }

    // Construct Class
    _construct();

    ///////////////////////
    // ** Public API  ** //
    ///////////////////////

    the.update = function() {
        return _update();
    }

    the.getHeight = function() {
        return _getHeight();
    }

    the.getElement = function() {
        return the.element;
    }

    the.destroy = function() {
        return _destroy();
    }
};

// Static methods
KTScroll.getInstance = function(element) {
    if ( element !== null && KTUtil.data(element).has('scroll') ) {
        return KTUtil.data(element).get('scroll');
    } else {
        return null;
    }
}

// Create instances
KTScroll.createInstances = function(selector = '[data-kt-scroll="true"]') {
    var body = document.getElementsByTagName("BODY")[0];

    // Initialize Menus
    var elements = body.querySelectorAll(selector);

    if ( elements && elements.length > 0 ) {
        for (var i = 0, len = elements.length; i < len; i++) {
            new KTScroll(elements[i]);
        }
    }
}

// Window resize handling
window.addEventListener('resize', function() {
    var timer;
    var body = document.getElementsByTagName("BODY")[0];

    KTUtil.throttle(timer, function() {
        // Locate and update Offcanvas instances on window resize
        var elements = body.querySelectorAll('[data-kt-scroll="true"]');

        if ( elements && elements.length > 0 ) {
            for (var i = 0, len = elements.length; i < len; i++) {
                var scroll = KTScroll.getInstance(elements[i]);
                if (scroll) {
                    scroll.update();
                }
            }
        }
    }, 200);
});

// Global initialization
KTScroll.init = function() {
    KTScroll.createInstances();
};

// On document ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', KTScroll.init);
} else {
    KTScroll.init();
}


// Class definition
var KTScrolltop = function(element, options) {
    ////////////////////////////
    // ** Private variables  ** //
    ////////////////////////////
    var the = this;
    var body = document.getElementsByTagName("BODY")[0];

    if ( typeof element === "undefined" || element === null ) {
        return;
    }

    // Default options
    var defaultOptions = {
        offset: 300,
        speed: 600
    };

    ////////////////////////////
    // ** Private methods  ** //
    ////////////////////////////

    var _construct = function() {
        if (KTUtil.data(element).has('scrolltop')) {
            the = KTUtil.data(element).get('scrolltop');
        } else {
            _init();
        }
    }

    var _init = function() {
        // Variables
        the.options = KTUtil.deepExtend({}, defaultOptions, options);
        the.uid = KTUtil.getUniqueId('scrolltop');
        the.element = element;

        // Set initialized
        the.element.setAttribute('data-kt-scrolltop', 'true');

        // Event Handlers
        _handlers();

        // Bind Instance
        KTUtil.data(the.element).set('scrolltop', the);
    }

    var _handlers = function() {
        var timer;

        window.addEventListener('scroll', function() {
            KTUtil.throttle(timer, function() {
                _scroll();
            }, 200);
        });

        KTUtil.addEvent(the.element, 'click', function(e) {
            e.preventDefault();

            _go();
        });
    }

    var _scroll = function() {
        var offset = parseInt(_getOption('offset'));

        var pos = KTUtil.getScrollTop(); // current vertical position

        if ( pos > offset ) {
            if ( body.hasAttribute('data-kt-scrolltop') === false ) {
                body.setAttribute('data-kt-scrolltop', 'on');
            }
        } else {
            if ( body.hasAttribute('data-kt-scrolltop') === true ) {
                body.removeAttribute('data-kt-scrolltop');
            }
        }
    }

    var _go = function() {
        var speed = parseInt(_getOption('speed'));

        KTUtil.scrollTop(0, speed);
    }

    var _getOption = function(name) {
        if ( the.element.hasAttribute('data-kt-scrolltop-' + name) === true ) {
            var attr = the.element.getAttribute('data-kt-scrolltop-' + name);
            var value = KTUtil.getResponsiveValue(attr);

            if ( value !== null && String(value) === 'true' ) {
                value = true;
            } else if ( value !== null && String(value) === 'false' ) {
                value = false;
            }

            return value;
        } else {
            var optionName = KTUtil.snakeToCamel(name);

            if ( the.options[optionName] ) {
                return KTUtil.getResponsiveValue(the.options[optionName]);
            } else {
                return null;
            }
        }
    }

    var _destroy = function() {
        KTUtil.data(the.element).remove('scrolltop');
    }

    // Construct class
    _construct();

    ///////////////////////
    // ** Public API  ** //
    ///////////////////////

    // Plugin API
    the.go = function() {
        return _go();
    }

    the.getElement = function() {
        return the.element;
    }

    the.destroy = function() {
        return _destroy();
    }
};

// Static methods
KTScrolltop.getInstance = function(element) {
    if (element && KTUtil.data(element).has('scrolltop')) {
        return KTUtil.data(element).get('scrolltop');
    } else {
        return null;
    }
}

// Create instances
KTScrolltop.createInstances = function(selector = '[data-kt-scrolltop="true"]') {
    var body = document.getElementsByTagName("BODY")[0];

    // Initialize Menus
    var elements = body.querySelectorAll(selector);
    var scrolltop;

    if ( elements && elements.length > 0 ) {
        for (var i = 0, len = elements.length; i < len; i++) {
            scrolltop = new KTScrolltop(elements[i]);
        }
    }
}

// Global initialization
KTScrolltop.init = function() {
    KTScrolltop.createInstances();
};

// On document ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', KTScrolltop.init);
} else {
    KTScrolltop.init();
}



// Class definition
var KTDrawer = function (element, options) {
    //////////////////////////////
    // ** Private variables  ** //
    //////////////////////////////
    var the = this;
    var body = document.getElementsByTagName("BODY")[0];

    if (typeof element === "undefined" || element === null) {
        return;
    }

    // Default options
    var defaultOptions = {
        overlay: true,
        direction: 'end',
        baseClass: 'drawer',
        overlayClass: 'drawer-overlay'
    };

    ////////////////////////////
    // ** Private methods  ** //
    ////////////////////////////

    var _construct = function () {
        if (KTUtil.data(element).has('drawer')) {
            the = KTUtil.data(element).get('drawer');
        } else {
            _init();
        }
    }

    var _init = function () {
        // Variables
        the.options = KTUtil.deepExtend({}, defaultOptions, options);
        the.uid = KTUtil.getUniqueId('drawer');
        the.element = element;
        the.overlayElement = null;
        the.name = the.element.getAttribute('data-kt-drawer-name');
        the.shown = false;
        the.lastWidth;
        the.toggleElement = null;

        // Set initialized
        the.element.setAttribute('data-kt-drawer', 'true');

        // Event Handlers
        _handlers();

        // Update Instance
        _update();

        // Bind Instance
        KTUtil.data(the.element).set('drawer', the);
    }

    var _handlers = function () {
        var togglers = _getOption('toggle');
        var closers = _getOption('close');

        if (togglers !== null && togglers.length > 0) {
            KTUtil.on(body, togglers, 'click', function (e) {
                e.preventDefault();

                the.toggleElement = this;
                _toggle();
            });
        }

        if (closers !== null && closers.length > 0) {
            KTUtil.on(body, closers, 'click', function (e) {
                e.preventDefault();

                the.closeElement = this;
                _hide();
            });
        }
    }

    var _toggle = function () {
        if (KTEventHandler.trigger(the.element, 'kt.drawer.toggle', the) === false) {
            return;
        }

        if (the.shown === true) {
            _hide();
        } else {
            _show();
        }

        KTEventHandler.trigger(the.element, 'kt.drawer.toggled', the);
    }

    var _hide = function () {
        if (KTEventHandler.trigger(the.element, 'kt.drawer.hide', the) === false) {
            return;
        }

        the.shown = false;

        _deleteOverlay();

        body.removeAttribute('data-kt-drawer-' + the.name, 'on');
        body.removeAttribute('data-kt-drawer');

        KTUtil.removeClass(the.element, the.options.baseClass + '-on');

        if (the.toggleElement !== null) {
            KTUtil.removeClass(the.toggleElement, 'active');
        }

        KTEventHandler.trigger(the.element, 'kt.drawer.after.hidden', the) === false
    }

    var _show = function () {
        if (KTEventHandler.trigger(the.element, 'kt.drawer.show', the) === false) {
            return;
        }

        the.shown = true;

        _createOverlay();
        body.setAttribute('data-kt-drawer-' + the.name, 'on');
        body.setAttribute('data-kt-drawer', 'on');

        KTUtil.addClass(the.element, the.options.baseClass + '-on');

        if (the.toggleElement !== null) {
            KTUtil.addClass(the.toggleElement, 'active');
        }

        KTEventHandler.trigger(the.element, 'kt.drawer.shown', the);
    }

    var _update = function () {
        var width = _getWidth();
        var direction = _getOption('direction');

        // Reset state
        if (KTUtil.hasClass(the.element, the.options.baseClass + '-on') === true && String(body.getAttribute('data-kt-drawer-' + the.name + '-')) === 'on') {
            the.shown = true;
        } else {
            the.shown = false;
        }

        // Activate/deactivate
        if (_getOption('activate') === true) {
            KTUtil.addClass(the.element, the.options.baseClass);
            KTUtil.addClass(the.element, the.options.baseClass + '-' + direction);
            KTUtil.css(the.element, 'width', width, true);

            the.lastWidth = width;
        } else {
            KTUtil.css(the.element, 'width', '');

            KTUtil.removeClass(the.element, the.options.baseClass);
            KTUtil.removeClass(the.element, the.options.baseClass + '-' + direction);

            _hide();
        }
    }

    var _createOverlay = function () {
        if (_getOption('overlay') === true) {
            the.overlayElement = document.createElement('DIV');

            KTUtil.css(the.overlayElement, 'z-index', KTUtil.css(the.element, 'z-index') - 1); // update

            body.append(the.overlayElement);

            KTUtil.addClass(the.overlayElement, _getOption('overlay-class'));

            KTUtil.addEvent(the.overlayElement, 'click', function (e) {
                e.preventDefault();
                _hide();
            });
        }
    }

    var _deleteOverlay = function () {
        if (the.overlayElement !== null) {
            KTUtil.remove(the.overlayElement);
        }
    }

    var _getOption = function (name) {
        if (the.element.hasAttribute('data-kt-drawer-' + name) === true) {
            var attr = the.element.getAttribute('data-kt-drawer-' + name);
            var value = KTUtil.getResponsiveValue(attr);

            if (value !== null && String(value) === 'true') {
                value = true;
            } else if (value !== null && String(value) === 'false') {
                value = false;
            }

            return value;
        } else {
            var optionName = KTUtil.snakeToCamel(name);

            if (the.options[optionName]) {
                return KTUtil.getResponsiveValue(the.options[optionName]);
            } else {
                return null;
            }
        }
    }

    var _getWidth = function () {
        var width = _getOption('width');

        if (width === 'auto') {
            width = KTUtil.css(the.element, 'width');
        }

        return width;
    }

    var _destroy = function () {
        KTUtil.data(the.element).remove('drawer');
    }

    // Construct class
    _construct();

    ///////////////////////
    // ** Public API  ** //
    ///////////////////////

    // Plugin API
    the.toggle = function () {
        return _toggle();
    }

    the.show = function () {
        return _show();
    }

    the.hide = function () {
        return _hide();
    }

    the.isShown = function () {
        return the.shown;
    }

    the.update = function () {
        _update();
    }

    the.goElement = function () {
        return the.element;
    }

    the.destroy = function () {
        return _destroy();
    }

    // Event API
    the.on = function (name, handler) {
        return KTEventHandler.on(the.element, name, handler);
    }

    the.one = function (name, handler) {
        return KTEventHandler.one(the.element, name, handler);
    }

    the.off = function (name) {
        return KTEventHandler.off(the.element, name);
    }

    the.trigger = function (name, event) {
        return KTEventHandler.trigger(the.element, name, event, the, event);
    }
};

// Static methods
KTDrawer.getInstance = function (element) {
    if (element !== null && KTUtil.data(element).has('drawer')) {
        return KTUtil.data(element).get('drawer');
    } else {
        return null;
    }
}

// Hide all drawers and skip one if provided
KTDrawer.hideAll = function (skip = null, selector = '[data-kt-drawer="true"]') {
    var items = document.querySelectorAll(selector);

    if (items && items.length > 0) {
        for (var i = 0, len = items.length; i < len; i++) {
            var item = items[i];
            var drawer = KTDrawer.getInstance(item);

            if (!drawer) {
                continue;
            }

            if (skip) {
                if (item !== skip) {
                    drawer.hide();
                }
            } else {
                drawer.hide();
            }
        }
    }
}

// Update all drawers
KTDrawer.updateAll = function (selector = '[data-kt-drawer="true"]') {
    var items = document.querySelectorAll(selector);

    if (items && items.length > 0) {
        for (var i = 0, len = items.length; i < len; i++) {
            var item = items[i];
            var drawer = KTDrawer.getInstance(item);

            if (drawer) {
                drawer.update();
                ;
            }
        }
    }
}

// Create instances
KTDrawer.createInstances = function (selector = '[data-kt-drawer="true"]') {
    var body = document.getElementsByTagName("BODY")[0];

    // Initialize Menus
    var elements = body.querySelectorAll(selector);
    var drawer;

    if (elements && elements.length > 0) {
        for (var i = 0, len = elements.length; i < len; i++) {
            drawer = new KTDrawer(elements[i]);
        }
    }
}

// Toggle instances
KTDrawer.handleShow = function () {
    // External drawer toggle handler
    KTUtil.on(document.body, '[data-kt-drawer-show="true"][data-kt-drawer-target]', 'click', function (e) {
        var element = document.querySelector(this.getAttribute('data-kt-drawer-target'));

        if (element) {
            KTDrawer.getInstance(element).show();
        }
    });
}

// Dismiss instances
KTDrawer.handleDismiss = function () {
    // External drawer toggle handler
    KTUtil.on(document.body, '[data-kt-drawer-dismiss="true"]', 'click', function (e) {
        var element = this.closest('[data-kt-drawer="true"]');

        if (element) {
            var drawer = KTDrawer.getInstance(element);
            if (drawer.isShown()) {
                drawer.hide();
            }
        }
    });
}

// Window resize Handling
window.addEventListener('resize', function () {
    var timer;
    var body = document.getElementsByTagName("BODY")[0];

    KTUtil.throttle(timer, function () {
        // Locate and update drawer instances on window resize
        var elements = body.querySelectorAll('[data-kt-drawer="true"]');

        if (elements && elements.length > 0) {
            for (var i = 0, len = elements.length; i < len; i++) {
                var drawer = KTDrawer.getInstance(elements[i]);
                if (drawer) {
                    drawer.update();
                }
            }
        }
    }, 200);
});

// Global initialization
KTDrawer.init = function () {
    KTDrawer.createInstances();
    KTDrawer.handleShow();
    KTDrawer.handleDismiss();
};

// On document ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', KTDrawer.init);
} else {
    KTDrawer.init();
}
