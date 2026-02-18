define(['knockout', 'ko.mapping'], function (ko, mapping) {
    //ko.mapping = mapping;

    var Caller = function () {
    };

    Caller.prototype.get = function (entity, params) {
        'use strict';
        var dfr = new $.Deferred();
        var entity = entity || '';
        dfr.promise().done(function () {});
        switch (entity) {

            case 'domainList':
                $.postJSON("/hosting/domain/listdomains", function (data) {
                    if (data.result && data.result.length >=0) {
                        return dfr.resolve(data.result);
                    } else dfr.reject();
                }).fail(function () {
                });
                break;

            case 'apachehandlersList':
                $.postJSON("/hosting/tools/access/handlers/list", function (data) {
                    if (data && data.result) {
                        return dfr.resolve(data.result);
                    }
                });
                break;

            case 'mssqlDBList':
                $.postJSON("/hosting/database/mssql/list", function (data) {
                    if (data && data.result) {
                        return dfr.resolve(data.result);
                    }
                });
                break;

            case 'mysqlDBList':
                $.postJSON("/hosting/database/listmysqldatabases", function (data) {
                    if (data && data.result) {
                        return dfr.resolve(data.result);
                    }
                });
                break;

            case 'emailList':
                $.postJSON("/hosting/email/listallemails", function (data) {
                    if (data && data.result) {
                        return dfr.resolve(data.result);
                    }
                });
                break;
            default:
                return dfr.reject('No existe la llamada que intenta realizar');
                break;
        }
        return dfr.promise();
    };

    return Caller;
});