<?php

namespace AmeliaBooking\Domain\Services\TimeSlot;

use AmeliaBooking\Domain\Entity\Booking\Appointment\Appointment;
use AmeliaBooking\Domain\Entity\Booking\Appointment\CustomerBooking;
use AmeliaBooking\Domain\Entity\Location\Location;
use AmeliaBooking\Domain\Entity\Schedule\DayOff;
use AmeliaBooking\Domain\Entity\Schedule\Period;
use AmeliaBooking\Domain\Entity\Schedule\PeriodService;
use AmeliaBooking\Domain\Entity\Schedule\SpecialDay;
use AmeliaBooking\Domain\Entity\Schedule\SpecialDayPeriod;
use AmeliaBooking\Domain\Entity\Schedule\SpecialDayPeriodService;
use AmeliaBooking\Domain\Entity\Schedule\TimeOut;
use AmeliaBooking\Domain\Entity\Schedule\WeekDay;
use AmeliaBooking\Domain\Entity\Bookable\Service\Service;
use AmeliaBooking\Domain\Entity\User\Provider;
use AmeliaBooking\Domain\Services\DateTime\DateTimeService;
use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\ValueObjects\String\BookingStatus;
use AmeliaBooking\Domain\ValueObjects\String\Status;

/**
 * Class TimeSlotService
 *
 * @package AmeliaBooking\Domain\Services\TimeSlot
 */
class TimeSlotService
{
    /** @noinspection MoreThanThreeArgumentsInspection */
    /**
     * get special dates intervals for provider.
     *
     * @param Provider   $provider
     * @param Collection $locations
     * @param int        $locationId
     * @param int        $serviceId
     * @param int        $minimumSlotTime
     *
     * @return array
     * @throws \Exception
     */
    private function getProviderSpecialDayIntervals($provider, $locations, $locationId, $serviceId, $minimumSlotTime)
    {
        $intervals = [];

        $visibleLocationsCount = 0;

        /** @var Location $location */
        foreach ($locations->getItems() as $location) {
            if ($location->getStatus()->getValue() === Status::VISIBLE) {
                $visibleLocationsCount++;
            }
        }

        $providerLocationId = $provider->getLocationId() && $provider->getLocationId()->getValue() ?
            $provider->getLocationId()->getValue() : null;

        /** @var SpecialDay $specialDay */
        foreach ($provider->getSpecialDayList()->getItems() as $specialDay) {
            $specialDates = [];

            $endDateCopy = clone $specialDay->getEndDate()->getValue();

            $specialDaysPeriod = new \DatePeriod(
                $specialDay->getStartDate()->getValue(),
                new \DateInterval('P1D'),
                $endDateCopy->modify('+1 day')
            );

            /** @var \DateTime $day */
            foreach ($specialDaysPeriod as $day) {
                $specialDates[$day->format('Y-m-d')] = true;
            }

            $specialDatesIntervals = [];

            /** @var SpecialDayPeriod $period */
            foreach ($specialDay->getPeriodList()->getItems() as $period) {
                $periodLocationId = $period->getLocationId() && $period->getLocationId()->getValue() ?
                    $period->getLocationId()->getValue() : null;

                if (($periodLocationId && $visibleLocationsCount > 0 &&
                        $locations->getItem($periodLocationId)->getStatus()->getValue() === Status::HIDDEN) ||
                    (!$periodLocationId && $providerLocationId && $visibleLocationsCount > 0 &&
                        $locations->getItem($providerLocationId)->getStatus()->getValue() === Status::HIDDEN)
                ) {
                    continue;
                }

                $hasService = $period->getPeriodServiceList()->length() === 0;

                $hasLocation = $locationId === null ||
                    ($periodLocationId === $locationId) ||
                    (!$periodLocationId && $providerLocationId === $locationId);

                /** @var SpecialDayPeriodService $periodService */
                foreach ($period->getPeriodServiceList()->getItems() as $periodService) {
                    if ($periodService->getServiceId()->getValue() === $serviceId) {
                        $hasService = true;
                    }
                }

                $start = $this->getSeconds($period->getStartTime()->getValue()->format('H:i:s'));
                $end = $this->getSeconds($this->getEndTimeString($period->getEndTime()->getValue()->format('H:i:s')));

                if ($hasLocation && $hasService && ($end - $start) >= $minimumSlotTime) {
                    $specialDatesIntervals['free'][$start] = [$start, $end, $periodLocationId ?: $providerLocationId];
                }
            }

            $intervals[] = [
                'dates'     => $specialDates,
                'intervals' => $specialDatesIntervals
            ];
        }

        return array_reverse($intervals);
    }

    /** @noinspection MoreThanThreeArgumentsInspection */
    /**
     * get week days intervals for provider.
     *
     * @param Provider   $provider
     * @param Collection $locations
     * @param int        $serviceId
     * @param int        $locationId
     * @param int        $minimumSlotTime
     *
     * @return array
     * @throws \AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException
     */
    private function getProviderWeekDaysIntervals($provider, $locations, $locationId, $serviceId, $minimumSlotTime)
    {
        $intervals = [];

        $visibleLocationsCount = 0;

        /** @var Location $location */
        foreach ($locations->getItems() as $location) {
            if ($location->getStatus()->getValue() === Status::VISIBLE) {
                $visibleLocationsCount++;
            }
        }

        $providerLocationId = $provider->getLocationId() && $provider->getLocationId()->getValue() ?
            $provider->getLocationId()->getValue() : null;

        /** @var WeekDay $weekDay */
        foreach ($provider->getWeekDayList()->getItems() as $weekDay) {
            $dayIndex = $weekDay->getDayIndex()->getValue();

            $intervals[$dayIndex]['busy'] = [];
            $intervals[$dayIndex]['free'] = [];

            /** @var TimeOut $timeOut */
            foreach ($weekDay->getTimeOutList()->getItems() as $timeOut) {
                $start = $this->getSeconds($timeOut->getStartTime()->getValue()->format('H:i:s'));

                $intervals[$dayIndex]['busy'][$start] = [
                    $start,
                    $this->getSeconds($timeOut->getEndTime()->getValue()->format('H:i:s'))
                ];
            }

            /** @var Period $period */
            foreach ($weekDay->getPeriodList()->getItems() as $period) {
                $periodLocationId = $period->getLocationId() && $period->getLocationId()->getValue() ?
                    $period->getLocationId()->getValue() : null;

                if (($periodLocationId && $visibleLocationsCount > 0 &&
                        $locations->getItem($periodLocationId)->getStatus()->getValue() === Status::HIDDEN) ||
                    (!$periodLocationId && $providerLocationId && $visibleLocationsCount > 0 &&
                        $locations->getItem($providerLocationId)->getStatus()->getValue() === Status::HIDDEN)
                ) {
                    continue;
                }

                $hasService = $period->getPeriodServiceList()->length() === 0;

                $hasLocation = $locationId === null ||
                    ($periodLocationId === $locationId) ||
                    (!$periodLocationId && $providerLocationId === $locationId);

                /** @var PeriodService $periodService */
                foreach ($period->getPeriodServiceList()->getItems() as $periodService) {
                    if ($periodService->getServiceId()->getValue() === $serviceId) {
                        $hasService = true;
                    }
                }

                $start = $this->getSeconds($period->getStartTime()->getValue()->format('H:i:s'));
                $end = $this->getSeconds($this->getEndTimeString($period->getEndTime()->getValue()->format('H:i:s')));

                if ($hasLocation && $hasService && ($end - $start) >= $minimumSlotTime) {
                    $intervals[$dayIndex]['free'][$start] = [$start, $end, $periodLocationId ?: $providerLocationId];
                }
            }

            if ($weekDay->getPeriodList()->length() === 0) {
                $start = $this->getSeconds($weekDay->getStartTime()->getValue()->format('H:i:s'));
                $end = $this->getSeconds($this->getEndTimeString($weekDay->getEndTime()->getValue()->format('H:i:s')));

                if (($end - $start) >= $minimumSlotTime) {
                    $intervals[$dayIndex]['free'][$start] = [$start, $end, $providerLocationId];
                }
            }

            $intervals[$dayIndex]['free'] = $this->getAvailableIntervals(
                $intervals[$dayIndex]['free'],
                isset($intervals[$dayIndex]['busy']) ? $intervals[$dayIndex]['busy'] : []
            );
        }

        return $intervals;
    }

    /** @noinspection MoreThanThreeArgumentsInspection */
    /**
     * get appointment intervals for provider.
     *
     * @param Provider   $provider
     * @param Collection $locations
     * @param int        $serviceId
     * @param int        $locationId
     * @param int        $personsCount
     * @param boolean    $bookIfPending
     * @param array      $weekDaysIntervals
     * @param array      $specialDatesIntervals
     * @return array
     * @throws \AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException
     */
    private function getProviderAppointmentIntervals(
        $provider,
        $locations,
        $serviceId,
        $locationId,
        $personsCount,
        $bookIfPending,
        &$weekDaysIntervals,
        &$specialDatesIntervals
    ) {
        $intervals = [];

        $providerLocationId = $provider->getLocationId() ? $provider->getLocationId()->getValue() : null;

        /** @var Appointment $app */
        foreach ($provider->getAppointmentList()->getItems() as $app) {
            $intervalDateTime = $app->getBookingStart()->getValue();
            $dateString = $intervalDateTime->format('Y-m-d');
            $appLocationId = $app->getLocationId() ? $app->getLocationId()->getValue() : null;

            $start = $this->getSeconds($intervalDateTime->format('H:i:s')) -
                ($app->getService() && $app->getService()->getTimeBefore() ? $app->getService()->getTimeBefore()->getValue() : 0);

            $end = $this->getSeconds($this->getEndTimeString($app->getBookingEnd()->getValue()->format('H:i:s'))) +
                ($app->getService() && $app->getService()->getTimeAfter() ? $app->getService()->getTimeAfter()->getValue() : 0);

            if ($app->getServiceId()->getValue() === $serviceId) {
                $persons = 0;

                /** @var CustomerBooking $booking */
                foreach ($app->getBookings()->getItems() as $booking) {
                    $persons += $booking->getPersons()->getValue();
                }

                $status = $app->getStatus()->getValue();
                $hasCapacity = ($persons + $personsCount) <= $app->getService()->getMaxCapacity()->getValue();

                $hasLocation =
                    !$locationId ||
                    ($app->getLocationId() && $app->getLocationId()->getValue() === $locationId) ||
                    (!$app->getLocationId() && $providerLocationId === $locationId) ||
                    ($appLocationId &&
                        $appLocationId === $locationId &&
                        $locations->getItem($appLocationId)->getStatus()->getValue() === Status::VISIBLE) ||
                    (!$appLocationId && $providerLocationId &&
                        $locations->getItem($providerLocationId)->getStatus()->getValue() === Status::VISIBLE);

                if (($hasLocation && $status === BookingStatus::APPROVED && $hasCapacity) ||
                    ($hasLocation && $status === BookingStatus::PENDING && ($bookIfPending || $hasCapacity))
                ) {
                    $intervals[$dateString]['available'][$intervalDateTime->format('H:i')] =
                        [
                            'locationId' => $app->getLocationId() ? $app->getLocationId()->getValue() : $providerLocationId,
                            'places'     => $app->getService()->getMaxCapacity()->getValue() - $persons,
                            'seconds'    => $this->getSeconds($intervalDateTime->format('H:i:s'))
                        ];
                }
            }

            if (isset($specialDatesIntervals['dates'][$dateString][$dateString], $specialDatesIntervals['intervals']['busy'][$start]) &&
                $specialDatesIntervals['intervals']['busy'][$start][1] > $end
            ) {
                $end = $specialDatesIntervals['intervals']['busy'][$start][1];
            }

            if (isset($weekDaysIntervals[DateTimeService::getDayIndex($dateString)]['busy'][$start]) &&
                $weekDaysIntervals[DateTimeService::getDayIndex($dateString)]['busy'][$start][1] > $end
            ) {
                $end = $weekDaysIntervals[DateTimeService::getDayIndex($dateString)]['busy'][$start][1];
            }

            if (isset($intervals[$dateString]['occupied'][$start]) && $intervals[$dateString]['occupied'][$start][1] > $end) {
                $end = $intervals[$dateString]['occupied'][$start][1];
            }

            $intervals[$dateString]['occupied'][$start] = [$start, $end];
        }

        return $intervals;
    }

    /**
     * get provider day off dates.
     *
     * @param Provider $provider
     *
     * @return array
     * @throws \Exception
     */
    private function getProviderDayOffDates($provider)
    {
        $dates = [];

        /** @var DayOff $dayOff */
        foreach ($provider->getDayOffList()->getItems() as $dayOff) {
            $endDateCopy = clone $dayOff->getEndDate()->getValue();

            $dayOffPeriod = new \DatePeriod(
                $dayOff->getStartDate()->getValue(),
                new \DateInterval('P1D'),
                $endDateCopy->modify('+1 day')
            );

            /** @var \DateTime $date */
            foreach ($dayOffPeriod as $date) {
                $dateFormatted = $dayOff->getRepeat()->getValue() ? $date->format('m-d') : $date->format('Y-m-d');
                $dates[$dateFormatted] = $dateFormatted;
            }
        }

        return $dates;
    }

    /**
     * get available appointment intervals.
     *
     * @param array $availableIntervals
     * @param array $unavailableIntervals
     *
     * @return array
     */
    private function getAvailableIntervals(&$availableIntervals, $unavailableIntervals)
    {
        $parsedAvailablePeriod = [];

        ksort($availableIntervals);
        ksort($unavailableIntervals);

        foreach ($availableIntervals as $available) {
            $parsedAvailablePeriod[] = $available;

            foreach ($unavailableIntervals as $unavailable) {
                if ($parsedAvailablePeriod) {
                    $lastAvailablePeriod = $parsedAvailablePeriod[sizeof($parsedAvailablePeriod) - 1];

                    if ($unavailable[0] >= $lastAvailablePeriod[0] && $unavailable[1] <= $lastAvailablePeriod[1]) {
                        // unavailable interval is inside available interval
                        $fixedPeriod = array_pop($parsedAvailablePeriod);

                        if ($fixedPeriod[0] !== $unavailable[0]) {
                            $parsedAvailablePeriod[] = [$fixedPeriod[0], $unavailable[0], $fixedPeriod[2]];
                        }

                        if ($unavailable[1] !== $fixedPeriod[1]) {
                            $parsedAvailablePeriod[] = [$unavailable[1], $fixedPeriod[1], $fixedPeriod[2]];
                        }
                    }  elseif ($unavailable[0] <= $lastAvailablePeriod[0] && $unavailable[1] >= $lastAvailablePeriod[1]) {
                        // available interval is inside unavailable interval
                        array_pop($parsedAvailablePeriod);
                    } elseif ($unavailable[0] <= $lastAvailablePeriod[0] && $unavailable[1] >= $lastAvailablePeriod[0] && $unavailable[1] <= $lastAvailablePeriod[1]) {
                        // unavailable interval intersect start of available interval
                        $fixedPeriod = array_pop($parsedAvailablePeriod);

                        if ($unavailable[1] !== $fixedPeriod[1]) {
                            $parsedAvailablePeriod[] = [$unavailable[1], $fixedPeriod[1], $fixedPeriod[2]];
                        }
                    } elseif ($unavailable[0] >= $lastAvailablePeriod[0] && $unavailable[0] <= $lastAvailablePeriod[1] && $unavailable[1] >= $lastAvailablePeriod[1]) {
                        // unavailable interval intersect end of available interval
                        $fixedPeriod = array_pop($parsedAvailablePeriod);

                        if ($fixedPeriod[0] !== $unavailable[0]) {
                            $parsedAvailablePeriod[] = [$fixedPeriod[0], $unavailable[0], $fixedPeriod[2]];
                        }
                    }
                }
            }
        }

        return $parsedAvailablePeriod;
    }

    /**
     * @param Service  $service
     * @param Provider $provider
     * @param int      $personsCount
     *
     * @return bool
     *
     * @throws \Exception
     */
    public function getOnlyAppointmentsSlots($service, $provider, $personsCount)
    {
        $getOnlyAppointmentsSlots = false;

        if ($provider->getServiceList()->keyExists($service->getId()->getValue())) {
            /** @var Service $providerService */
            $providerService = $provider->getServiceList()->getItem($service->getId()->getValue());

            if ($personsCount < $providerService->getMinCapacity()->getValue()) {
                $getOnlyAppointmentsSlots = true;
            }
        }

        return $getOnlyAppointmentsSlots;
    }

    /** @noinspection MoreThanThreeArgumentsInspection */
    /**
     * @param Service    $service
     * @param int        $locationId
     * @param Collection $providers
     * @param Collection $locations
     * @param array      $globalDaysOffDates
     * @param \DateTime  $startDateTime
     * @param \DateTime  $endDateTime
     * @param int        $personsCount
     * @param boolean    $bookIfPending
     * @param boolean    $bookIfNotMin
     * @param boolean    $bookAfterMin
     *
     * @return array
     * @throws \Exception
     */
    public function getFreeTime(
        Service $service,
        $locationId,
        Collection $locations,
        Collection $providers,
        array $globalDaysOffDates,
        \DateTime $startDateTime,
        \DateTime $endDateTime,
        $personsCount,
        $bookIfPending,
        $bookIfNotMin,
        $bookAfterMin
    ) {

        $weekDayIntervals = [];
        $appointmentIntervals = [];
        $daysOffDates = [];
        $specialDayIntervals = [];
        $getOnlyAppointmentsSlots = [];

        $serviceId = $service->getId()->getValue();

        $minimumSlotDuration = ($service->getTimeBefore() ? $service->getTimeBefore()->getValue() : 0) +
            $service->getDuration()->getValue() +
            ($service->getTimeAfter() ? $service->getTimeAfter()->getValue() : 0);

        /** @var Provider $provider */
        foreach ($providers->getItems() as $provider) {
            $providerId = $provider->getId()->getValue();

            $getOnlyAppointmentsSlots[$providerId] = $bookIfNotMin && $bookAfterMin ? $this->getOnlyAppointmentsSlots(
                $service,
                $provider,
                $personsCount
            ) : false;

            $daysOffDates[$providerId] = $this->getProviderDayOffDates($provider);

            $weekDayIntervals[$providerId] = $this->getProviderWeekDaysIntervals(
                $provider,
                $locations,
                $locationId,
                $serviceId,
                $minimumSlotDuration
            );

            $specialDayIntervals[$providerId] = $this->getProviderSpecialDayIntervals(
                $provider,
                $locations,
                $locationId,
                $serviceId,
                $minimumSlotDuration
            );

            $appointmentIntervals[$providerId] = $this->getProviderAppointmentIntervals(
                $provider,
                $locations,
                $serviceId,
                $locationId,
                $personsCount,
                $bookIfPending,
                $weekDayIntervals[$providerId],
                $specialDayIntervals[$providerId]
            );
        }

        $freeDateIntervals = [];

        foreach ($appointmentIntervals as $providerKey => $providerDates) {
            foreach ((array)$providerDates as $dateKey => $dateIntervals) {
                $dayIndex = DateTimeService::getDayIndex($dateKey);

                $specialDayDateKey = null;

                foreach ((array)$specialDayIntervals[$providerKey] as $specialDayKey => $specialDays) {
                    if (array_key_exists($dateKey, $specialDays['dates'])) {
                        $specialDayDateKey = $specialDayKey;
                        break;
                    }
                }

                if ($specialDayDateKey !== null && isset($specialDayIntervals[$providerKey][$specialDayDateKey]['intervals']['free'])) {
                    // get free intervals if it is special day
                    $freeDateIntervals[$providerKey][$dateKey] = $this->getAvailableIntervals(
                        $specialDayIntervals[$providerKey][$specialDayDateKey]['intervals']['free'],
                        $dateIntervals['occupied']
                    );
                } elseif (isset($weekDayIntervals[$providerKey][$dayIndex]['free']) && !isset($specialDayIntervals[$providerKey][$specialDayDateKey]['intervals'])) {
                    // get free intervals if it is working day
                    $unavailableIntervals =
                        $weekDayIntervals[$providerKey][$dayIndex]['busy'] + $dateIntervals['occupied'];

                    $intersectedTimes = array_intersect(
                        array_keys($weekDayIntervals[$providerKey][$dayIndex]['busy']),
                        array_keys($dateIntervals['occupied'])
                    );

                    foreach ($intersectedTimes as $time) {
                        $unavailableIntervals[$time] =
                            $weekDayIntervals[$providerKey][$dayIndex]['busy'][$time] >
                            $dateIntervals['occupied'][$time] ?
                                $weekDayIntervals[$providerKey][$dayIndex]['busy'][$time] :
                                $dateIntervals['occupied'][$time];
                    }

                    $freeDateIntervals[$providerKey][$dateKey] = $this->getAvailableIntervals(
                        $weekDayIntervals[$providerKey][$dayIndex]['free'],
                        $unavailableIntervals
                    );
                }
            }
        }


        // create calendar
        $period = new \DatePeriod(
            $startDateTime,
            new \DateInterval('P1D'),
            $endDateTime
        );

        $calendar = [];

        /** @var \DateTime $day */
        foreach ($period as $day) {
            $currentDate = $day->format('Y-m-d');
            $dayIndex    = (int)$day->format('N');

            $isGlobalDayOff = array_key_exists($currentDate, $globalDaysOffDates) ||
                array_key_exists($day->format('m-d'), $globalDaysOffDates);

            if (!$isGlobalDayOff) {
                foreach ($weekDayIntervals as $providerKey => $providerWorkingHours) {
                    $isProviderDayOff = array_key_exists($currentDate, $daysOffDates[$providerKey]) ||
                        array_key_exists($day->format('m-d'), $daysOffDates[$providerKey]);

                    $specialDayDateKey = null;

                    foreach ((array)$specialDayIntervals[$providerKey] as $specialDayKey => $specialDays) {
                        if (array_key_exists($currentDate, $specialDays['dates'])) {
                            $specialDayDateKey = $specialDayKey;
                            break;
                        }
                    }

                    if (!$isProviderDayOff) {
                        if ($freeDateIntervals && isset($freeDateIntervals[$providerKey][$currentDate])) {
                            // get date intervals if there are appointments (special or working day)
                            $calendar[$currentDate][$providerKey] = [
                                'slots'     => $personsCount && $bookIfNotMin && isset($appointmentIntervals[$providerKey][$currentDate]['available']) ?
                                    $appointmentIntervals[$providerKey][$currentDate]['available'] : [],
                                'intervals' => $getOnlyAppointmentsSlots[$providerKey] ? [] : $freeDateIntervals[$providerKey][$currentDate],
                            ];
                        } else {
                            if ($specialDayDateKey !== null && isset($specialDayIntervals[$providerKey][$specialDayDateKey]['intervals']['free'])) {
                                // get date intervals if it is special day with out appointments
                                $calendar[$currentDate][$providerKey] = [
                                    'slots'     => [],
                                    'intervals' => $getOnlyAppointmentsSlots[$providerKey] ? [] : $specialDayIntervals[$providerKey][$specialDayDateKey]['intervals']['free']
                                ];
                            } elseif (isset($weekDayIntervals[$providerKey][$dayIndex]) && !isset($specialDayIntervals[$providerKey][$specialDayDateKey]['intervals'])) {
                                // get date intervals if it is working day without appointments
                                $calendar[$currentDate][$providerKey] = [
                                    'slots'     => [],
                                    'intervals' => $getOnlyAppointmentsSlots[$providerKey] ? [] : $weekDayIntervals[$providerKey][$dayIndex]['free']
                                ];
                            }
                        }
                    }
                }
            }
        }

        return $calendar;
    }

    /**
     *
     * @param string $endTime
     * @return string
     */
    private function getEndTimeString($endTime)
    {
        return $endTime === '00:00:00' ? '24:00:00' : $endTime;
    }

    /**
     * @param string $time
     *
     * @return int
     */
    public function getSeconds($time)
    {
        $timeParts = explode(':', $time);

        return $timeParts[0] * 60 * 60 + $timeParts[1] * 60 + $timeParts[2];
    }

    /**
     * @param array $data
     * @param int   $startTime
     * @param int   $endTime
     *
     * @return array
     */
    public function getFreeIntervals($data, $startTime, $endTime)
    {
        $result = [];

        ksort($data);

        $firstIntervalTime = true;

        $lastStartTime = $startTime;

        foreach ((array)$data as &$interval) {
            // Appointment is out of working hours
            if ($interval[0] >= $endTime || $interval[1] <= $startTime) {
                continue;
            }

            // Beginning or End of the Appointment is out of working hours
            if ($interval[0] < $startTime && $interval[1] <= $endTime) {
                $interval[0] = $startTime;
            } else if ($interval[0] >= $startTime && $interval[1] > $endTime) {
                $interval[1] = $endTime;
            }

            if ($lastStartTime !== $interval[0] && ($lastStartTime !== $startTime || ($firstIntervalTime && $lastStartTime !== $interval[0]))) {
                $firstIntervalTime = false;
                $result[$lastStartTime] = [
                    $lastStartTime,
                    $interval[0]
                ];
            }

            $lastStartTime = $interval[1];
        }

        if ($lastStartTime !== $endTime) {
            $result[$lastStartTime] = [
                $lastStartTime,
                $endTime
            ];
        }

        return $result;
    }

    /** @noinspection MoreThanThreeArgumentsInspection */
    /**
     * @param Service   $service
     * @param int       $requiredTime
     * @param array     $freeIntervals
     * @param int       $slotLength
     * @param \DateTime $startDateTime
     * @param bool      $serviceDurationAsSlot
     * @param bool      $bufferTimeInSlot
     * @param bool      $isFrontEndBooking
     *
     * @return array
     */
    public function getAppointmentFreeSlots(
        $service,
        $requiredTime,
        &$freeIntervals,
        $slotLength,
        $startDateTime,
        $serviceDurationAsSlot,
        $bufferTimeInSlot,
        $isFrontEndBooking
    ) {
        $result = [];

        if ($serviceDurationAsSlot && !$bufferTimeInSlot) {
            $requiredTime = $requiredTime -
                $service->getTimeBefore()->getValue() -
                $service->getTimeAfter()->getValue();
        }

        $currentDateTime = DateTimeService::getNowDateTimeObject();

        $currentDateString = $currentDateTime->format('Y-m-d');
        $currentTimeStringInSeconds = $this->getSeconds($currentDateTime->format('H:i:s'));

        $currentTimeInSeconds = $this->getSeconds($currentDateTime->format('H:i:s'));
        $currentDateFormatted = $currentDateTime->format('Y-m-d');
        $startTimeInSeconds = $this->getSeconds($startDateTime->format('H:i:s'));
        $startDateFormatted = $startDateTime->format('Y-m-d');

        $bookingLength = $serviceDurationAsSlot && $isFrontEndBooking ? $requiredTime : $slotLength;

        foreach ($freeIntervals as $dateKey => $dateProviders) {
            foreach ((array)$dateProviders as $providerKey => $provider) {
                foreach ((array)$provider['intervals'] as $timePeriod) {
                    if (!$bufferTimeInSlot && $serviceDurationAsSlot) {
                        $timePeriod[1] = $timePeriod[1] - $service->getTimeAfter()->getValue();
                    }

                    $customerTimeStart = $timePeriod[0] + $service->getTimeBefore()->getValue();

                    $providerTimeStart = $customerTimeStart - $service->getTimeBefore()->getValue();

                    $numberOfSlots = (int)(floor(($timePeriod[1] - $providerTimeStart - $requiredTime) / $bookingLength) + 1);

                    for ($i = 0; $i < $numberOfSlots; $i++) {
                        $timeSlot = $customerTimeStart + $i * $bookingLength;

                        if (($startDateFormatted !== $dateKey && ($serviceDurationAsSlot && !$bufferTimeInSlot ? $timeSlot <= $timePeriod[1] - $requiredTime : true)) ||
                            ($startDateFormatted === $dateKey && $startTimeInSeconds < $timeSlot) ||
                            ($startDateFormatted === $currentDateFormatted && $startDateFormatted === $dateKey && $startTimeInSeconds < $timeSlot && $currentTimeInSeconds < $timeSlot)
                        ) {
                            $time = sprintf('%02d', floor($timeSlot / 3600)) . ':'
                                . sprintf('%02d', floor(($timeSlot / 60) % 60));

                            $result[$dateKey][$time][] = [$providerKey, $timePeriod[2]];
                        }
                    }
                }

                foreach ((array)$provider['slots'] as $appointmentTime => $appointmentData) {
                    if ($currentDateString === $dateKey && ($currentTimeStringInSeconds > $appointmentData['seconds'] || $startTimeInSeconds > $appointmentData['seconds'])) {
                       continue;
                    }

                    $result[$dateKey][$appointmentTime][] = [$providerKey, $appointmentData['locationId'], $appointmentData['places']];
                }
            }

            if (isset($result[$dateKey])) {
                if (!$result[$dateKey]) {
                    unset($result[$dateKey]);
                } else {
                    ksort($result[$dateKey]);
                }
            }
        }

        return $result;
    }
}
