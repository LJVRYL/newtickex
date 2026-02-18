define(['knockout', 'ko.mapping', 'hosting/entities/record', 'input'], function(ko, mapping, Record, Input) {
    var Redirection = function(data) {
        'use strict';

        mediator.installTo(this);
        ko.mapping = mapping;
        this.entitiname = 'domain/Redirection';
        ko.mapping.fromJS(data, {}, this);
    };

    Redirection.prototype = new Record({});

    Redirection.prototype = new Input({});

    Redirection.prototype.eliminar = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idDomain": self.domain.id(),
            "Redirection": self.name.content()
        }};

        $.postJSON('/hosting/domain/removeRedirection', theData, function(e) {
            mediator.publish('RedirectionDeleted');
        }).always(function(data) {
            // ???
        });
    };

    return Redirection;
});