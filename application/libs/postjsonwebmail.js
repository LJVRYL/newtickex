jQuery && jQuery.extend({
    postJSON: function (url, data, callback, beforeSend) {
        var _datta = '';
        if (typeof data === 'function') {
            // Es de get
            callback = data;
        } else {
            callback === '';
            if (data && data.error) {
            } else {
                _datta = data;
            }
        }

        return jQuery.ajax({
            type: "POST",
            url: url,
            data: JSON.stringify(_datta),
            error: function () {
            },
            success: function (response) {
                var FerozoApp = window.FerozoHosting || window.FerozoDhm;
                if (response.error) {
                    switch (parseInt(response.error.code, 10)) {
                    case -401:
                        FerozoApp && FerozoApp.connection.needlogin(1);
                        break;
                    case -20:
                        break;
                    case 2000:
                        typeof callback === 'function' && callback(response);
                        break;
                    default:
                        typeof callback === 'function' && callback(response);
                        break;
                    }
                } else {
                    if (response.result && response.result.commandTemplate || response.result == true) {
                        FerozoApp && FerozoApp.tasksVM().init();
                    }
                    callback(response);
                }
            },

            dataType: "json",
            contentType: "application/json",
            processData: false,
            beforeSend: function (xhr) {
                typeof beforeSend === 'function' && beforeSend();
                xhr.setRequestHeader('CSRF-Token', window.FerozoUtils && FerozoUtils.security.getToken());
            }
        });
    }
});