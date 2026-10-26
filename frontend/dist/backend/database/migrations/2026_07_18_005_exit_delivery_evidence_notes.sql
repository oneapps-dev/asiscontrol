ALTER TABLE visits
  ADD COLUMN exit_observations TEXT NULL AFTER exited_at;

ALTER TABLE vehicles
  ADD COLUMN exit_observations TEXT NULL AFTER exited_at;

ALTER TABLE packages
  ADD COLUMN delivered_to VARCHAR(180) NULL AFTER delivered_at,
  ADD COLUMN delivery_observations TEXT NULL AFTER delivered_to;
