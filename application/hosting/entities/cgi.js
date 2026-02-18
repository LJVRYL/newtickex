define(['knockout', 'ko.mapping', 'hosting/entities/record', 'input'], function(ko, mapping, Record, Input) {
    /* ------------ Cgi -----------------*/
    var Cgi = function(data) {
        'use strict';
        mediator.installTo(this);
        ko.mapping = mapping;
        this.entitiname = 'domain/cgi';
        ko.mapping.fromJS(data, {}, this);
    };

    Cgi.prototype = new Record({});

    Cgi.prototype.constructor = Cgi;

    Cgi.prototype.eliminar = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idDomain": self.domain.id(),
            "Cgi": self.name.content()
        }};

        $.postJSON('/hosting/domain/removeCgi', theData, function(e) {
            mediator.publish('CgiDeleted');
        });
    };

    Cgi.prototype.save = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "iddomain": self.domain.id,
            'name': self.name.content(),
            'url': self.redirection.content()
        }};

        $.postJSON('/hosting/domain/createCgi', theData, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self[this.field]().error(this.errorDesc);
                });
            } else {
                mediator.publish('refreshCgiList');
                $('.modal').modal('hide');
            }
        });
    };

    Cgi.prototype.removeRedirection = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "iddomain": self.domain.id(),
            "name": self.name.content()
        }};

        $.postJSON('/hosting/domain/unsetredirectionCgi', theData, function(data) {
            mediator.publish('CgiDeleted');
            $('.modal').modal('hide');
        });
    };

    Cgi.prototype.setredirectionCgi = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idDomain": self.domain().id,
            "Cgi": self.name.content(),
            "url": self.redirection()
        }};

        $.postJSON('/hosting/domain/removeCgi', theData, function(data) {
            mediator.publish('CgiDeleted');
            $('.modal').modal('hide');
        });
    };
    return Cgi;

});