define(['knockout', 'git', 'ko.mapping'], function(ko, Git, mapping) {

    var gitVM = function() {
        var self = this;
        mediator.installTo(this);
        ko.mapping = mapping;
        this.title = "";
        this.inprocess = ko.observable(1);
        this.data = ko.observableArray([]);
        this.temp = ko.observable(new Git());
        this.gitLog = ko.observable('');
        this.autoDep = ko.observable('');
        this.sshKey = ko.observable('');
        this.setInprocess =  ko.computed({
            read: function () {
                return false;
            },
            write: function(action) {
                if (action == "+") {
                    self.inprocess(self.inprocess()+1);
                } else {
                    if(self.inprocess() <= 0 ) {
                        self.inprocess(0);
                    } else {
                         self.inprocess(self.inprocess()-1);
                    }
                }
            }
        },this);
        this.readyView =  ko.computed(function(){
            if (this.inprocess() >= 1 ) return "LOADING";
            if (this.inprocess() < 1 ) return "W-REPOS";
            return "LOADING";
        },this);
        this.sortDirection = ko.observable('asc');
        this.sortData = function() {
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.account().user == right.url() ? 0 : (left.url() < right.url() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.url() == right.url() ? 0 : (left.url() > right.url() ? -1 : 1);
                });
            }
        };

        this.subscribe('refreshGit', function() {
            var self = this;
            self.setInprocess("+");
            $.postJSON("/hosting/tools/listgit", function(data) {
                if (data.result) {
                    var mappingRules = {
                        create: function(options) {
                            return new Git(options.data);
                        }, key: function(item) {
                            return ko.utils.unwrapObservable(item.id);
                        }
                    };
                    ko.mapping.fromJS(data.result, mappingRules, self.data);
                }
            }).always(function(data) {
                self.setInprocess("-");
            });
        });

        this.newgit = function() {
            this.temp(new Git());
            $("#apuntar-nuevo").modal();
            $('.modal select').change();
        };

        this.init = function() {
            mediator.publish('refreshGit');
            self.setInprocess("-");
        };
    };

	gitVM.prototype.deployGit = function(git) {
		'use strict';
		var self = this;
		FerozoHosting.gitVM().temp(git);
        FerozoHosting.gitVM().setInprocess("+");
        var theData = { "params": {
            "idRepository": git.id()
        }};
        $.postJSON("/hosting/tools/deploygit", theData, function(data) {
            if (data.result) {
                FerozoHosting.gitVM().temp().regStatus(3);
            }
        }).always(function(data) {
            FerozoHosting.gitVM().setInprocess("-");
        });
    };

    gitVM.prototype.getGitLog = function(git) {
		'use strict';
		var self = this;
		FerozoHosting.gitVM().temp(git);
        FerozoHosting.gitVM().setInprocess("+");
        var theData = { "params": {
            "idRepository": git.id()
        }};
        $.postJSON("/hosting/tools/getgitlog", theData, function(data) {
            if (data.result) {
                FerozoHosting.gitVM().gitLog(data.result);
                $("#git-log").modal();
                $('.modal select').change();
            }
        }).always(function(data) {
            FerozoHosting.gitVM().setInprocess("-");
        });
    };

    gitVM.prototype.autoDeploy = function(git) {
		'use strict';
		var self = this;
        var urlInit = window.location.href.split('#')[0];
        FerozoHosting.gitVM().autoDep(urlInit + 'deploy/git/' + git.deploytoken());
        $("#auto-deploy").modal();
        $('.modal select').change();
    };

    gitVM.prototype.copyToClipboard = function() {
		'use strict';
		var self = this;

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(FerozoHosting.gitVM().autoDep());
        }
        else {
            const textarea = document.createElement('textarea');
            textarea.value = FerozoHosting.gitVM().autoDep();
            textarea.style.position = 'absolute';
            textarea.style.left = '-99999999px';
            document.body.prepend(textarea);
            textarea.select();
          
            try {
              document.execCommand('copy');
            } catch (err) {
              console.log(err);
            } finally {
              textarea.remove();
            }
        }
    };

    gitVM.prototype.getssh = function() {
		'use strict';
		var self = this;
        $.postJSON("/hosting/tools/getsshkey", function(data) {
            if (data.result) {
                FerozoHosting.gitVM().sshKey(data.result);
            }
        }).always(function(data) {
            FerozoHosting.gitVM().setInprocess("-");
            $('#generar-ssh').modal();
            $('.modal select').change();
        });
    };

    gitVM.prototype.genssh = function() {
		'use strict';
		var self = this;
        $.postJSON("/hosting/tools/generatesshkey", function(data) {
        }).always(function(data) {
            $('#generar-ssh').modal("hide");
        });
    };

    gitVM.prototype.copyToClipboardSsh = function() {
		'use strict';
		var self = this;
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(FerozoHosting.gitVM().sshKey());
        }
        else {
            const textarea = document.createElement('textarea');
            textarea.value = FerozoHosting.gitVM().sshKey();
            textarea.style.position = 'absolute';
            textarea.style.left = '-99999999px';
            document.body.prepend(textarea);
            textarea.select();
          
            try {
              document.execCommand('copy');
            } catch (err) {
              console.log(err);
            } finally {
              textarea.remove();
            }
        }
    };
    
    return gitVM;
});