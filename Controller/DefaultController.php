<?php

namespace Novosga\MonitorBundle\Controller;

use Exception;
use Novosga\Http\Envelope;
use Novosga\Entity\Unidade;
use Novosga\Service\AtendimentoService;
use Novosga\Service\FilaService;
use Novosga\Service\ServicoService;
use Novosga\Util\Arrays;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

/**
 * DefaultController
 *
 * @author Rogerio Lino <rogeriolino@gmail.com>
 */
class DefaultController extends Controller
{

    /**
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @Route("/", name="novosga_monitor_index")
     */
    public function indexAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $unidade = $request->getSession()->get('unidade');
        $servicos = $this->servicos($unidade, ' e.status = 1 ');

        // lista de prioridades para ser utilizada ao redirecionar senha
        $prioridades = $em
                    ->getRepository(\Novosga\Entity\Prioridade::class)
                    ->findBy([
                        'status' => 1
                    ], [
                        'peso' => 'ASC',
                        'nome' => 'ASC'
                    ]);

        return $this->render('NovosgaMonitorBundle:Default:index.html.twig', [
            'unidade' => $unidade,
            'servicos' => $servicos,
            'prioridades' => $prioridades,
            'milis' => time() * 1000
        ]);
    }

    private function servicos(Unidade $unidade, $where = '')
    {
        $em = $this->getDoctrine()->getManager();

        $service = new ServicoService($em);

        return $service->servicosUnidade($unidade, $where);
    }

    /**
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @Route("/ajax_update", name="novosga_monitor_ajaxupdate")
     */
    public function ajaxUpdateAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $envelope = new Envelope();
        $unidade = $request->getSession()->get('unidade');
        $filaService = new FilaService($em);
        
        try {
            if (!$unidade) {
                throw new Exception(_('Nenhuma unidade escolhida'));
            }
            
            $data = [];
            $ids = $request->get('ids');
            $ids = Arrays::valuesToInt(explode(',', $ids));
            
            if (count($ids)) {
                $data['total'] = 0;
                $servicos = $this->servicos($unidade, ' e.servico IN ('.implode(',', $ids).') ');
                $em = $this->getDoctrine()->getManager();
                if ($servicos) {
                    foreach ($servicos as $su) {
                        $rs = $filaService->filaServico($unidade, $su->getServico());
                        $total = count($rs);
                        // prevent overhead
                        if ($total) {
                            $fila = [];
                            foreach ($rs as $atendimento) {
                                $arr = $atendimento->jsonSerialize(true);
                                $fila[] = $arr;
                            }
                            $data['servicos'][$su->getServico()->getId()] = $fila;
                            $data['total']++;
                        }
                    }
                }
            }
            $envelope->setData($data);
        } catch (Exception $e) {
            $envelope->exception($e);
        }
        

        return $this->json($envelope);
    }

    /**
     * 
     * @param Request $request
     * @return Response
     * 
     * @Route("/info_senha", name="novosga_monitor_infosenha")
     */
    public function infoSenhaAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        
        $envelope = new Envelope();
        $unidade = $request->getSession()->get('unidade');
        
        try {
            if (!$unidade) {
                throw new Exception(_('Nenhuma unidade escolhida'));
            }
            
            $id = (int) $request->get('id');
            $service = new AtendimentoService($em);
            $atendimento = $service->buscaAtendimento($unidade, $id);
            
            if (!$atendimento) {
                throw new Exceptio(_('Atendimento inválido'));
            }
            
            $data = $atendimento->jsonSerialize();
            $envelope->setData($data);
        } catch (Exception $e) {
            $envelope->exception($e);
        }

        return $this->json($envelope);
    }

    /**
     * Busca os atendimentos a partir do número da senha.
     *
     * @param Request $request
     * 
     * @Route("/buscar", name="novosga_monitor_buscar")
     */
    public function buscarAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $data = [];
        $unidade = $request->getSession()->get('unidade');
        $envelope = new Envelope();
        
        try {
            if (!$unidade) {
                throw new Exception(_('Nenhuma unidade selecionada'));
            }
            
            $numero = $request->get('numero');
            $service = new AtendimentoService($em);
            $atendimentos = $service->buscaAtendimentos($unidade, $numero);
            $data['total'] = count($atendimentos);
            foreach ($atendimentos as $atendimento) {
                $data['atendimentos'][] = $atendimento->jsonSerialize();
            }

            $envelope->setData($data);
        } catch (Exception $e) {
            $envelope->exception($e);
        }
        
        
    }

    /**
     * Transfere o atendimento para outro serviço e prioridade.
     *
     * @param Request $request
     * 
     * @Route("/transferir", name="novosga_monitor_transferir")
     */
    public function transferirAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $envelope = new Envelope();
        
        try {
            $unidade = $request->getSession()->get('unidade');
            if (!$unidade) {
                throw new Exception(_('Nenhuma unidade selecionada'));
            }
            $id = (int) $request->get('id');
            $atendimento = $this->getAtendimento($unidade, $id);
            /*
             * TODO: verificar se o servico informado esta disponivel para a unidade.
             */
            $servico = (int) $request->get('servico');
            $prioridade = (int) $request->get('prioridade');

            $service = new AtendimentoService($em);
            $service->transferir($atendimento, $unidade, $servico, $prioridade);
        } catch (Exception $e) {
            $envelope->exception($e);
        }

        return $this->json($envelope);
    }

    /**
     * Reativa o atendimento para o mesmo serviço e mesma prioridade.
     * Só pode reativar atendimentos que foram: Cancelados ou Não Compareceu.
     *
     * @param Request $request
     * 
     * @Route("/reativar", name="novosga_monitor_reativar")
     */
    public function reativarAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $envelope = new Envelope();
        
        try {
            $unidade = $request->getSession()->get('unidade');
            if (!$unidade) {
                throw new Exception(_('Nenhuma unidade selecionada'));
            }
            $id = (int) $request->get('id');
            $conn = $em->getConnection();
            $status = implode(',', [AtendimentoService::SENHA_CANCELADA, AtendimentoService::NAO_COMPARECEU]);
            // reativa apenas se estiver finalizada (data fim diferente de nulo)
            $stmt = $conn->prepare("
                UPDATE
                    atendimentos
                SET
                    status = :status,
                    dt_fim = NULL
                WHERE
                    id = :id AND
                    unidade_id = :unidade AND
                    status IN ({$status})
            ");
            $stmt->bindValue('id', $id);
            $stmt->bindValue('status', AtendimentoService::SENHA_EMITIDA);
            $stmt->bindValue('unidade', $unidade->getId());
            $stmt->execute() > 0;
        } catch (Exception $e) {
            $envelope->exception($e);
        }

        return $this->json($envelope);
    }

    /**
     * Atualiza o status da senha para cancelado.
     *
     * @param Request $request
     * 
     * @Route("/cancelar", name="novosga_monitor_cancelar")
     */
    public function cancelarAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $envelope = new Envelope();
        
        try {
            $unidade = $request->getSession()->get('unidade');
            if (!$unidade) {
                throw new Exception(_('Nenhuma unidade selecionada'));
            }
            $id = (int) $request->get('id');
            $atendimento = $this->getAtendimento($unidade, $id);
            $service = new AtendimentoService($em);
            $service->cancelar($atendimento, $unidade);
        } catch (Exception $e) {
            $envelope->exception($e);
        }

        return $this->json($envelope);
    }

    private function getAtendimento(Unidade $unidade, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $atendimento = $em->find('Novosga\Entity\Atendimento', $id);
        if (!$atendimento || $atendimento->getServicoUnidade()->getUnidade()->getId() != $unidade->getId()) {
            throw new Exception(_('Atendimento inválido'));
        }
        if (!$atendimento) {
            throw new Exception(_('Atendimento inválido'));
        }

        return $atendimento;
    }
}
