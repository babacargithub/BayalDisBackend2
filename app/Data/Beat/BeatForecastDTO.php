<?php

namespace App\Data\Beat;

/**
 * Holds the forecasted sales figures for a beat's next scheduled occurrence.
 *
 * The forecast is derived from the average of the beat customers' actual sales
 * on the matching day-of-week within the past FORECAST_LOOKBACK_DAYS (15) days.
 *
 * All money values are integers (XOF).
 * A data_points_count of 0 means there was no historical data to base the forecast on.
 */
readonly class BeatForecastDTO
{
    public function __construct(
        /** Average total sales observed on past occurrences of this beat's day (XOF). */
        public int $forecastedTotalSales,

        /** Average total estimated profit observed on past occurrences of this beat's day (XOF). */
        public int $forecastedTotalProfit,

        /**
         * Number of past days (matching the beat's day-of-week) used to compute the average.
         * 0 means no historical data was available — forecast values will be 0.
         */
        public int $dataPointsCount,
    ) {}
}
