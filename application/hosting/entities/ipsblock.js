define(['knockout', 'input', 'hosting/entities/record', 'ko.mapping'], function(ko, Input, Record, mapping) {
    var Ipsblock = function(data) {
        'use strict';

        mediator.installTo(this);
        ko.mapping = mapping;
        this.rowstatus = ko.observable('0');//0=nada;1=delete
        this.id = ko.observable('');
        this.ip = ko.observable(new Input());
        var mappingRules = {
            'ip': {
                update: function(options) {
                    return ko.observable(new Input({
                        'content': options.data
                    }));
                }
            }
        };
        ko.mapping.fromJS(data, mappingRules, this);
    };

    Ipsblock.prototype.save = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "ip": self.ip().content()
        }};

        FerozoHosting.ipsblockVM() && FerozoHosting.ipsblockVM().inprocess(1);
        $.postJSON('/hosting/tools/createblockip', theData, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    FerozoHosting.ipsblockVM().temp()[this.field]().error(this.errorDesc);
                });

            } else {
                FerozoHosting.ipsblockVM().init();
                $('.modal').modal('hide');
            }
        }).always(function() {
            FerozoHosting.ipsblockVM() && FerozoHosting.ipsblockVM().inprocess(0);
        });
    };

    Ipsblock.prototype.remove = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "id": self.id()
        }};

        FerozoHosting.ipsblockVM() && FerozoHosting.ipsblockVM().inprocess(1);
        self.regStatus(4);
        $.postJSON('/hosting/tools/removeblockip', theData, function(e) {
            mediator.publish('ipsBlockRemoved');
        }).fail(function(data) {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
            FerozoHosting.ipsblockVM() && FerozoHosting.ipsblockVM().inprocess(0);
        });
    };

    return Ipsblock;
});