-- FuelMiser custom OSRM profile
--
-- This profile wraps the stock OSRM car profile shipped in the backend image
-- and applies FuelAU-specific tuning on top of it.
--
-- Limitation:
-- OSRM profiles are fixed at extraction time, so the exact 30 km short/long
-- switch cannot be decided per-route inside one Lua profile. This file is tuned
-- as a hybrid profile that leans toward time-saving on shorter trips while
-- still biasing toward major roads on longer trips.

local base = dofile('/opt/car.lua')

local function get_mode_name()
    return 'hybrid'
end

local function setup()
    local config = base.setup()

    config.properties.weight_name = 'duration'
    config.properties.left_hand_driving = true
    config.properties.use_turn_restrictions = true
    config.properties.continue_straight_at_waypoint = false
    config.properties.traffic_light_penalty = 12

    config.default_mode = mode.driving
    config.default_speed = 90
    config.oneway_handling = true
    config.side_road_multiplier = 0.88
    config.turn_penalty = 8.0
    config.turn_bias = 1.08

    config.speeds.highway = {
        motorway = 110,
        motorway_link = 95,
        trunk = 100,
        trunk_link = 85,
        primary = 90,
        primary_link = 80,
        secondary = 80,
        secondary_link = 70,
        tertiary = 70,
        tertiary_link = 60,
        unclassified = 60,
        residential = 50,
        living_street = 40,
        service = 30,
        track = 20,
    }

    config.highway_turn_classification = {
        motorway = 3,
        motorway_link = 3,
        trunk = 2,
        trunk_link = 2,
        primary = 1,
        primary_link = 1,
        secondary = 0,
        secondary_link = 0,
        tertiary = -1,
        tertiary_link = -1,
        unclassified = -2,
        residential = -3,
        living_street = -3,
        service = -4,
        track = -5,
    }

    config.route_length_threshold_km = 30
    config.route_strategy = get_mode_name()

    return config
end

function process_node(profile, node, result, relations)
    base.process_node(profile, node, result, relations)
end

function process_way(profile, way, result, relations)
    base.process_way(profile, way, result, relations)
end

function process_turn(profile, turn)
    base.process_turn(profile, turn)
end

return {
    setup = setup,
    process_way = process_way,
    process_node = process_node,
    process_turn = process_turn,
}
