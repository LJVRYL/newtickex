define(['knockout', 'domain', 'ipsblock', 'ko.mapping'], function(ko, Domain, Ipsblock, mapping) {

    var IpsBlockVM = function() {
        'use strict';
        var self = this;
        mediator.installTo(this);
        ko.mapping = mapping;
        this.inprocess = ko.observable(1);
        this.data = ko.observableArray();
        this.temp = ko.observable(new Ipsblock());
        this.sortDirection = ko.observable('asc');
        this.sortData = function() {
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    var lIp = left.ip().content().split('.')[0];
                    var rIp = right.ip().content().split('.')[0];
                    return lIp == rIp ? 0 : (lIp < rIp ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    var lIp = left.ip().content().split('.')[0];
                    var rIp = right.ip().content().split('.')[0];
                    return lIp == rIp ? 0 : (lIp > rIp ? -1 : 1);
                });
            }
        };

        this.subscribe('IpBlockUpdated', function(data) {
            if (data) {
               self.data([]);
               $.each(data, function() {
                   self.data.push(new Ipsblock(this));
               });
               //
           }
           FerozoHosting.ipsblockVM() && FerozoHosting.ipsblockVM().inprocess(0);
        });

        this.subscribe('ipsBlockAdded', function(data) {
            self.init();
            $('.modal').modal('hide');
        });

        this.subscribe('ipsBlockRemoved', function(data) {
            self.init();
        });

    };


    IpsBlockVM.prototype.init = function() {
        'use strict';
        var self = this;
        mediator.publish('RefreshTasks');
        mediator.publish('getIpBlocks');
        FerozoHosting.tasksVM().init();
   };


    return IpsBlockVM;
});
//-------- / DomainVM