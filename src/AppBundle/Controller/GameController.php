<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

use AppBundle\Entity\Week;
use AppBundle\Entity\Game;
use AppBundle\Entity\Team;
use AppBundle\Form\Type\GameType;
use AppBundle\Service\PickemService;
use AppBundle\Service\StatsService;
use AppBundle\Service\WeekService;

/**
 * @Route("/game")
 */
class GameController extends Controller
{
    /**
     * @Route("/{season}", name="app_game_index", defaults={"season": null})
     * @Route("/{season}/week/{week}", name="app_game_index_week", defaults={"week": null})
     */
    public function indexAction($season = null, $week = null, WeekService $weekService)
    {
        $result      = $weekService->currentWeek($season, $week);
        $week        = $result['week'];
        $season      = $result['season'];
        $seasonWeeks = $result['seasonWeeks'];

        $em         = $this->getDoctrine()->getManager();
        $repository = $em->getRepository('AppBundle:Game');
        $games      = $repository->findGamesByWeek($week);

        $playoffGames = [];

        if ($week->getNumber() == 16) {
            foreach ($games as $game) {
                if (strpos($game['bowlName'], 'CFP Semifinal')) {
                    if (! array_key_exists('firstSemifinal', $playoffGames)) {
                        $playoffGames['firstSemifinal'] = $game;
                    } else {
                        if ($game['id'] < $playoffGames['firstSemifinal']['id']) {
                            $playoffGames['secondSemifinal'] = $playoffGames['firstSemifinal'];
                            $playoffGames['firstSemifinal'] = $game;
                        } else {
                            $playoffGames['secondSemifinal'] = $game;
                        }
                    }
                } elseif ($game['bowlName'] == 'CFP National Championship Game') {
                    $playoffGames['championship'] = $game;
                }
            }
        }

        return $this->render('AppBundle:Game:index.html.twig', [
            'games'         => $games,
            'season'        => $season,
            'week'          => $week,
            'season_weeks'  => $seasonWeeks,
            'playoff_games' => $playoffGames,
        ]);
    }

    /**
     * @Route("/{game}/show", name="app_game_show")
     */
    public function showAction(Game $game, PickemService $pickemService, StatsService $statsService)
    {
        $em         = $this->getDoctrine()->getManager();
        $repository = $em->getRepository('AppBundle:Week');
        $week       = $repository->createQueryBuilder('w')
            ->where('w.startDate <= :date')
            ->andWhere('w.endDate >= :date')
            ->setParameter('date', $game->getDate())
            ->getQuery()
            ->getSingleResult();

        $repository = $em->getRepository('AppBundle:Prediction');
        $userPrediction = $repository->findOneBy([
            'person' => $this->getUser(),
            'game'   => $game,
        ]);

        $gamePredictions = $pickemService->picksByWeek($week, $game);
        $gamedayPicks    = $pickemService->gamedayWeekPicks($week, $game);

        $gameComparison = null;

        if ($game->getHomeTeam() && $game->getAwayTeam()) {
            $gameComparison  = $statsService->teamComparison($game->getHomeTeam()->getId(), $game->getAwayTeam()->getId());
        }

        return $this->render('AppBundle:Game:show.html.twig', [
            'game'             => $game,
            'game_predictions' => $gamePredictions,
            'user_prediction'  => $userPrediction,
            'gameday_picks'    => $gamedayPicks,
            'game_comparison'  => $gameComparison,
        ]);
    }

    /**
     * @Route("/add/single", name="app_game_add")
     * @Route("/add/{slug}/team", name="app_game_add_team")
     * @Security("is_granted('ROLE_MANAGE')")
     */
    public function addAction(Request $request, Team $team = null)
    {
        $games = [];
        if ($team) {
            $em         = $this->getDoctrine()->getManager();
            $repository = $em->getRepository('AppBundle:Game');
            $games      = $repository->findGamesByTeam($team);
        }

        $game = new Game();
        $form = $this->createForm(GameType::class, $game);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($game);
            $em->flush();

            $this->addFlash('success', 'Game added');

            if ($team) {
                return $this->redirectToRoute('app_game_add_team', [
                    'slug' => $team->getSlug(),
                ]);
            }

            return $this->redirectToRoute('app_game_show', [
                'game' => $game->getId(),
            ]);
        }

        return $this->render('AppBundle:Game:addEdit.html.twig', [
            'form'  => $form->createView(),
            'team'  => $team,
            'games' => $games,
        ]);
    }

    /**
     * @Route("/{game}/edit", name="app_game_edit")
     * @Security("is_granted('ROLE_MANAGE')")
     */
    public function editAction(Game $game, Request $request)
    {
        $form = $this->createForm(GameType::class, $game);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->flush();

            $this->addFlash('success', 'Game saved');
            return $this->redirectToRoute('app_game_show', [
                'game' => $game->getId(),
            ]);
        }

        return $this->render('AppBundle:Game:addEdit.html.twig', [
            'form' => $form->createView(),
            'game' => $game,
        ]);
    }

    /**
     * @Route("/{game}/remove", name="app_game_remove")
     * @Security("is_granted('ROLE_MANAGE')")
     */
    public function removeAction(Game $game)
    {
        $em = $this->getDoctrine()->getManager();
        $em->remove($game);
        $em->flush();

        $this->addFlash('warning', 'Game removed');
        return $this->redirectToRoute('app_game_index');
    }

    /**
     * @Route("/{game}/toggle-cancel", name="app_game_toggle_cancel")
     * @Security("is_granted('ROLE_MANAGE')")
     */
    public function toggleCancelAction(Game $game)
    {
        $game->setCanceled(!$game->isCanceled());

        $em = $this->getDoctrine()->getManager();
        $em->flush();

        return $this->redirectToRoute('app_game_show', [
            'game' => $game->getId(),
        ]);
    }

    /**
     * @Route("/team/{slug}/game/{game}/outcome", name="app_game_outcome")
     * @Security("is_granted('ROLE_MANAGE')")
     */
    public function outcomeAction(Team $team, Game $game)
    {
        $game->setWinningTeam($team);

        $em = $this->getDoctrine()->getManager();
        $em->flush();

        $this->addFlash('success', 'Winning team set');
        return $this->redirectToRoute('app_game_show', [
            'game' => $game->getId(),
        ]);
    }

    /**
     * @Route("/{season}/week/{week}/lines", name="app_game_lines")
     */
    public function linesAction($season = null, $week = null, WeekService $weekService, PickemService $pickemService, StatsService $statsService)
    {
        $result      = $weekService->currentWeek($season, $week);
        $week        = $result['week'];
        $season      = $result['season'];
        $seasonWeeks = $result['seasonWeeks'];

        $em         = $this->getDoctrine()->getManager();
        $repository = $em->getRepository('AppBundle:Game');
        $games      = $repository->findGamesByWeek($week);

        $gamePicks         = $pickemService->picksByWeek($week);
        $calculatedWinners = $statsService->gameWinners($games);

        $guessedCorrect      = [];
        $guessedCorrectCount = 0;

        foreach ($games as $game) {
            $guessedCorrect[$game['id']] = false;

            $winningChance = $game['winningChance'];

            if ($winningChance !== null) {
                $awayChance = $winningChance['away'];
                $homeChance = $winningChance['home'];

                $calculatedWinners[$game->getId()]['awayChance'] = $awayChance;
                $calculatedWinners[$game->getId()]['homeChance'] = $homeChance;
            } else {
                $awayChance = $calculatedWinners[$game['id']]['awayChance'];
                $homeChance = $calculatedWinners[$game['id']]['homeChance'];
            }

            if (($awayChance > $homeChance and $game['winningTeam']['id'] == $game['awayTeam']['id']) or ($homeChance > $awayChance and $game['winningTeam']['id'] == $game['homeTeam']['id'])) {
                $guessedCorrect[$game['id']] = true;
                $guessedCorrectCount++;
            }
        }

        return $this->render('AppBundle:Game:lines.html.twig', [
            'games'                 => $games,
            'season'                => $season,
            'week'                  => $week,
            'season_weeks'          => $seasonWeeks,
            'game_picks'            => $gamePicks,
            'calculated_winners'    => $calculatedWinners,
            'guessed_correct'       => $guessedCorrect,
            'guessed_correct_count' => $guessedCorrectCount,
        ]);
    }

    /**
     * @Route("/{season}/week/{week}/set-lines", name="app_game_lines_set")
     * @Security("is_granted('ROLE_MANAGE')")
     */
    public function setLinesAction($season = null, $week = null, WeekService $weekService, StatsService $statsService)
    {
        $result      = $weekService->currentWeek($season, $week);
        $week        = $result['week'];
        $season      = $result['season'];
        $seasonWeeks = $result['seasonWeeks'];

        $em         = $this->getDoctrine()->getManager();
        $repository = $em->getRepository('AppBundle:Game');
        $games      = $repository->findGamesByWeek($week);

        $calculatedWinners = $statsService->gameWinners($games);

        foreach ($games as $game) {
            $winningChance = [
                'home' => $calculatedWinners[$game->getId()]['homeChance'],
                'away' => $calculatedWinners[$game->getId()]['awayChance'],
            ];

            $game->setWinningChance($winningChance);
        }

        $em->flush();

        $this->addFlash('success', 'Lines added');
        return $this->redirectToRoute('app_game_lines', [
            'week'   => $week->getNumber(),
            'season' => $season,
        ]);
    }
}
