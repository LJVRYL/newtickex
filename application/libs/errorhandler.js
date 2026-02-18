window.onerror = function(msg, url, line, col, error) {
    var reportingUrl = '/common/security/frontend/error/handle';

    var errorObj = {
        msg: msg,
        url: url,
        line: line,
        col: col,
        href: window.location.href,
        stack: error.stack && error.stack.split("\n"),
        browser: window.navigator.userAgent,
        date: new window.Date().toISOString().slice(0, 19).replace('T', ' '),
        version: window.jQuery && jQuery('script[src*="js?v="]').attr('src').match(/[0-9]{4,}$/) + ''
    };

    if (errorObj.msg === 'uncaught exception: out of memory') {
        return;
    }

    return (function() {
        //console.warn('FzErrorHandler reporting', JSON.parse(JSON.stringify(errorObj)));
        var theData = {
            params: errorObj
        };

        return window.jQuery && jQuery.postJSON(reportingUrl, theData, function(data) {
        }).fail(function(data) {
        }).always(function(data) {
        });
    })();
};