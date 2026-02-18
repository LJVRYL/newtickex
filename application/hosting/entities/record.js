define(['knockout', 'ko.mapping'], function(ko, mapping) {
    /* ------------ INPUT -----------------*/
    function Record(data) {
        'use strict';
        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    Record.prototype.clear = function() {
        var self = this;
        var send = {
            "params": {
                "id": self.id
            }
        };
        $.postJSON('/hosting/' + self.entitiname + '/removefail', send, function() {
        }).done(function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self[this.field]().error(this.errorDesc);
                });
            } else {
                FerozoHosting[FerozoHosting.activeSection() + 'VM']().init();
                $('.modal').modal('hide');
            }
        });
    };
    
    return Record;
});