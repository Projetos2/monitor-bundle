/**
 * Novo SGA - Monitor
 * @author Rogerio Lino <rogeriolino@gmail.com>
 */
(function () {
    'use strict'
    
    var app = new Vue({
        el: '#monitor',
        data: {
            search: '',
            searchResult: [],
            servicos: [],
            atendimento: null,
            novoServico: '',
            novaPrioridade: '',
            connected: false,
            totalAtendimentosPendentes: 0,
            tempoMedioEspera: null,
            tempoMaximoEspera: null,
            tempoMinimoEspera: null
        },
        methods: {
            init: function () {
                var self = this;
                
                App.Websocket.connect();

                App.Websocket.on('connect', function () {
                    console.log('connected!');
                    App.Websocket.emit('register user', {
                        secret: wsSecret,
                        user: usuario.id,
                        unity: unidade.id
                    });
                });

                // ajax polling fallback
                App.Websocket.on('reconnect_failed', function () {
                    App.Websocket.connect();
                    console.log('ws timeout, ajax polling fallback');
                    self.connected = false;
                    self.update();
                });

                App.Websocket.on('register ok', function () {
                    console.log('registered!');
                    self.connected = true;
                });

                App.Websocket.on('update queue', function () {
                    console.log('update queue: do update!');
                    self.update();
                });
                
                self.update();
            },
            
            update: function () {
                var self = this;
                App.ajax({
                    url: App.url('/novosga.monitor/ajax_update'),
                    data: {
                        ids: ids.join(',')
                    },
                    success: function (response) {
                        self.servicos = response.data;

                        let somaSenhas = 0;
                        let horas = 0;
                        let minutos = 0;
                        let segundos = 0;
                        let maior = 0;
                        let maiorHoras = 0;
                        let maiorMinutos = 0;
                        let maiorSegundos = 0;
                        let menor = 9999999999999999999999;
                        let menorHoras = 0;
                        let menorMinutos = 0;
                        let menorSegundos = 0;
                        
                        
                        response.data.forEach(servico=>{
                        	servico.fila.forEach(atendimento=>{
                        		somaSenhas += 1;

                        		const hora = atendimento.tempoEspera.split(':')
                               	const horaDate = new Date(1970, 0, 1, hora[0], hora[1], hora[2])
                        		
                        		const timestamp = horaDate.getTime()
                        		
  
                        		if(timestamp > maior){
                        			maior = timestamp;
                        			maiorHoras = new Date(maior).getHours();
                        			maiorMinutos = new Date(maior).getMinutes();
                        			maiorSegundos = new Date(maior).getSeconds();
                        		}
                        		
                        		if(timestamp < menor){
                        			menor = timestamp;
                        			menorHoras = new Date(menor).getHours();
                        			menorMinutos = new Date(menor).getMinutes();
                        			menorSegundos = new Date(menor).getSeconds();
                        		}
                        		
                        		horas += horaDate.getHours();
                        		minutos += horaDate.getMinutes();
                        		segundos += horaDate.getSeconds(); 
                        		
                            })
                        })
                        
                        function formataData(n) {
                        	let k = parseFloat(n).toPrecision(2)
                        	if(k.length == 3){
                        		return '0' + k.split('.')[0]
                        	}else{
                        		return k
                        	}
                        }
                        self.totalAtendimentosPendentes = somaSenhas
                        if(self.totalAtendimentosPendentes >= 1){
                        self.tempoMedioEspera = formataData(horas/somaSenhas) 
                        	+ ':' + formataData(minutos/somaSenhas) 
                        	+ ':' + formataData(segundos/somaSenhas);
                        }else{
                        	self.tempoMedioEspera = formataData(0) 
                        	+ ':' + formataData(0) 
                        	+ ':' + formataData(0);
                        }
                        
                        
                        self.tempoMaximoEspera = formataData(maiorHoras) 
                    	+ ':' + formataData(maiorMinutos) 
                    	+ ':' + formataData(maiorSegundos);
                        
                        self.tempoMinimoEspera = formataData(menorHoras) 
                    	+ ':' + formataData(menorMinutos) 
                    	+ ':' + formataData(menorSegundos);
                    }
                });
            },
            
            /**
             * Busca informacoes do atendimento pelo id.
             */
            view: function (atendimento) {
                var self = this;
                App.ajax({
                    url: App.url('/novosga.monitor/info_senha/') + atendimento.id,
                    success: function (response) {
                        self.atendimento = response.data;
                        $('#dialog-view').modal('show');
                    }
                });
            },

            consulta: function () {
                $('#dialog-busca').modal('show');
                this.consultar();
            },

            consultar: function () {
                var self = this;
                
                App.ajax({
                    url: App.url('/novosga.monitor/buscar'),
                    data: {
                        numero: self.search
                    },
                    success: function (response) {
                        self.searchResult = response.data;
                    }
                });
            },

            transfere: function (atendimento) {
                this.atendimento = atendimento;
                $('#dialog-transfere').modal('show');
            },

            transferir: function (atendimento, novoServico, novaPrioridade) {
                var self = this;
                swal({
                    title: alertTitle,
                    text: alertTransferir,
                    type: "warning",
                    buttons: [
                        labelNao,
                        labelSim
                    ],
                    //dangerMode: true,
                })
                .then(function (ok) {
                    if (!ok) {
                        return;
                    }
                    
                    App.ajax({
                        url: App.url('/novosga.monitor/transferir/') + atendimento.id,
                        type: 'post',
                        data: {
                            servico: novoServico,
                            prioridade: novaPrioridade
                        },
                        success: function () {
                            App.Websocket.emit('change ticket', {
                                unity: unidade.id
                            });
                            $('.modal').modal('hide');
                            
                            if (!self.connected) {
                                self.update();
                            }
                        }
                    });
                });
            },

            reativar: function(atendimento) {
                var self = this;
                swal({
                    title: alertTitle,
                    text: alertReativar,
                    type: "warning",
                    buttons: [
                        labelNao,
                        labelSim
                    ],
                    //dangerMode: true,
                })
                .then(function (ok) {
                    if (!ok) {
                        return;
                    }
                    
                    App.ajax({
                        url: App.url('/novosga.monitor/reativar/') + atendimento.id,
                        type: 'post',
                        success: function () {
                            App.Websocket.emit('change ticket', {
                                unity: unidade.id
                            });
                            $('.modal').modal('hide');
                            
                            if (!self.connected) {
                                self.update();
                            }
                        }
                    });
                });
            },

            cancelar: function(atendimento) {
                var self = this;
                swal({
                    title: alertTitle,
                    text: alertCancelar,
                    type: "warning",
                    buttons: [
                        labelNao,
                        labelSim
                    ],
                    //dangerMode: true,
                })
                .then(function (ok) {
                    if (!ok) {
                        return;
                    }
            
                    App.ajax({
                        url: App.url('/novosga.monitor/cancelar/') + atendimento.id,
                        type: 'post',
                        success: function () {
                            App.Websocket.emit('change ticket', {
                                unity: unidade.id
                            });
                            $('.modal').modal('hide');
                            
                            if (!self.connected) {
                                self.update();
                            }
                        }
                    });
                });
            },
            
            totalPorSituacao: function (fila, prioridade) {
                var filtered = fila.filter(function (atendimento) {
                    if (prioridade) {
                        return atendimento.prioridade.peso > 0;
                    }
                    return atendimento.prioridade.peso === 0;
                });
                return filtered.length;
            }
        }
    });
    
    app.init();
})();